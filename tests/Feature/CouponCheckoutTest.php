<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Redemption at the money entry points: the promo code discounts the hosted-checkout
 * up-front charge (and binds to the subscription on the settled webhook), and the management
 * subscribe API applies + binds a coupon and refuses a bad one. The Starter plan is DKK
 * 29 000; 20% off is 23 200.
 */
class CouponCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);
        $this->seed(CatalogSeeder::class);
    }

    public function test_a_checkout_session_carries_a_coupon_and_the_intent_is_discounted(): void
    {
        $this->fakeGateway();
        $this->coupon('SAVE20', 20);

        $headers = $this->orgWithToken('org_ck');
        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_ck',
            'plan' => 'starter',
            'return_url' => 'https://merchant.example/done',
            'coupon' => 'save20',
        ], $headers)->assertCreated();

        $session = BillingSession::query()->where('organization_id', 'org_ck')->firstOrFail();
        $this->assertSame('SAVE20', $session->coupon_code);

        // The checkout page shows the discounted price, and the intent charges it.
        $this->get('/billing/checkout/'.$session->token)->assertOk()->assertSee('SAVE20');

        // The charge is the tax-aware GROSS of the discounted net (HP2): 232.00 net
        // (290.00 − 20%) + 25% DK VAT = 290.00 gross.
        $this->postJson('/billing/checkout/'.$session->token.'/intent')
            ->assertOk()
            ->assertJsonPath('amount.minor', 29_000);
    }

    public function test_an_invalid_checkout_coupon_is_refused_with_422(): void
    {
        $this->coupon('EXPIRED', 20, ['redeem_by' => now()->subDay()]);

        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_bad',
            'plan' => 'starter',
            'return_url' => 'https://merchant.example/done',
            'coupon' => 'EXPIRED',
        ], $this->orgWithToken('org_bad'))->assertStatus(422);
    }

    public function test_the_settled_webhook_binds_the_coupon_to_the_subscription(): void
    {
        $this->fakeGateway();
        $this->coupon('FOREVER20', 20, ['duration' => 'forever']);

        $headers = $this->orgWithToken('org_bind');
        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_bind',
            'plan' => 'starter',
            'return_url' => 'https://merchant.example/done',
            'coupon' => 'FOREVER20',
        ], $headers)->assertCreated();

        $session = BillingSession::query()->where('organization_id', 'org_bind')->firstOrFail();
        $this->postJson('/billing/checkout/'.$session->token.'/intent')->assertOk();
        $reference = (string) $session->refresh()->payment_reference;

        $this->postSettlement($reference, 29_000);

        $subscription = Subscription::query()->where('organization_id', 'org_bind')->firstOrFail();
        $binding = SubscriptionCoupon::query()->where('subscription_id', $subscription->id)->first();
        $this->assertInstanceOf(SubscriptionCoupon::class, $binding);
        $this->assertSame('FOREVER20', $binding->code);
        $this->assertSame(1, Coupon::query()->where('code', 'FOREVER20')->first()?->times_redeemed);
    }

    public function test_the_management_subscribe_api_applies_and_binds_a_coupon(): void
    {
        $this->coupon('API25', 25);
        $headers = $this->orgWithToken('org_api');

        $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_api',
            'plan' => 'starter',
            'coupon' => 'API25',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('coupon.code', 'API25')
            // 25% off 29 000 recurring = 7 250 discount, 21 750 discounted.
            ->assertJsonPath('coupon.discount_minor', 7_250)
            ->assertJsonPath('coupon.discounted_minor', 21_750);

        $subscription = Subscription::query()->where('organization_id', 'org_api')->firstOrFail();
        $this->assertNotNull(SubscriptionCoupon::query()->where('subscription_id', $subscription->id)->first());
    }

    public function test_the_management_subscribe_api_refuses_an_unknown_coupon_before_subscribing(): void
    {
        $headers = $this->orgWithToken('org_apibad');

        $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_apibad',
            'plan' => 'starter',
            'coupon' => 'NOPE',
        ], $headers)->assertStatus(422);

        $this->assertSame(0, Subscription::query()->where('organization_id', 'org_apibad')->count());
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(string $code, int $percent, array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'code' => $code,
            'discount_type' => 'percent',
            'percent_off' => $percent,
            'duration' => 'once',
            'applies_to' => 'all',
            'active' => true,
        ], $overrides));
    }

    /** @return array<string, string> */
    private function orgWithToken(string $id): array
    {
        Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function fakeGateway(): FakePaymentGateway
    {
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw_ref'), null, PaymentIntentStatus::Succeeded);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function postSettlement(string $reference, int $amountMinor): TestResponse
    {
        $body = json_encode([
            'event_id' => 'evt_'.$reference,
            'type' => 'payment.settled',
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => 'DKK',
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/webhooks/manual',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET)],
            $body,
        );
    }
}
