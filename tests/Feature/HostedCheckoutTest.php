<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The hosted checkout page + flow (ADR-0009 Path A): the page renders only for a valid,
 * unexpired session token; intent creation returns a gateway client secret; and the
 * subscription is activated STRICTLY on the gateway's settled webhook (never on the
 * client-side confirmation, and never on a `requires_action` intent). The gateway is the
 * engine's {@see FakePaymentGateway}, so the server side is exercised end-to-end without
 * live keys.
 */
class HostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);
        $this->seed(CatalogSeeder::class);
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

    private function openCheckout(string $org): BillingSession
    {
        $this->postJson('/api/v1/checkout-sessions', [
            'org' => $org,
            'plan' => 'starter',
            'return_url' => 'https://merchant.example/done',
        ], $this->orgWithToken($org))->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->firstOrFail();
    }

    private function fakeGateway(PaymentIntentStatus $status = PaymentIntentStatus::Succeeded): FakePaymentGateway
    {
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw_ref'), null, $status);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    public function test_the_checkout_page_renders_for_a_valid_token(): void
    {
        $session = $this->openCheckout('org_render');

        $this->get('/billing/checkout/'.$session->token)
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('payment-element', false);
    }

    public function test_the_checkout_page_404s_for_an_invalid_token(): void
    {
        $this->get('/billing/checkout/not-a-real-token')->assertNotFound();
    }

    public function test_intent_creation_returns_a_client_secret(): void
    {
        $this->fakeGateway();
        $session = $this->openCheckout('org_intent');

        $response = $this->postJson('/billing/checkout/'.$session->token.'/intent');

        $response->assertOk()
            ->assertJsonPath('gateway', 'fake')
            ->assertJsonPath('publishable_key', 'pub_fake')
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('amount.minor', 29_000)
            ->assertJsonPath('amount.currency', 'DKK');

        $this->assertIsString($response->json('client_secret'));
        $this->assertStringStartsWith('cs_pi_', (string) $response->json('client_secret'));

        // The stable settlement reference was stamped on the session.
        $this->assertNotNull($session->refresh()->payment_reference);
    }

    public function test_the_settled_webhook_activates_the_subscription_and_completes_the_session(): void
    {
        $this->fakeGateway();
        $session = $this->openCheckout('org_activate');

        // The customer's page creates the intent (stamps the settlement reference).
        $this->postJson('/billing/checkout/'.$session->token.'/intent')->assertOk();
        $reference = $session->refresh()->payment_reference;
        $this->assertIsString($reference);

        // No subscription and a still-pending session BEFORE the settled webhook.
        $this->assertSame(0, Subscription::query()->where('organization_id', 'org_activate')->count());
        $this->getJson('/billing/checkout/'.$session->token.'/status')->assertJsonPath('complete', false);

        // The gateway's settled webhook activates the subscription exactly once.
        $this->postSettlement($reference, 29_000);

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => 'org_activate',
            'status' => 'active',
        ]);

        $this->getJson('/billing/checkout/'.$session->token.'/status')
            ->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('return_url', 'https://merchant.example/done');

        // Exactly-once: a re-delivery is a no-op, still a single active subscription.
        $this->postSettlement($reference, 29_000)->assertJsonPath('applied', false);
        $this->assertSame(1, Subscription::query()->where('organization_id', 'org_activate')->count());
    }

    public function test_a_requires_action_intent_does_not_activate(): void
    {
        $this->fakeGateway(PaymentIntentStatus::RequiresAction);
        $session = $this->openCheckout('org_sca');

        $this->postJson('/billing/checkout/'.$session->token.'/intent')
            ->assertOk()
            ->assertJsonPath('status', 'requires_action')
            ->assertJsonPath('requires_action', true);

        // An SCA challenge is pending on the element — nothing is activated, no settled
        // webhook has arrived, and the session stays pending.
        $this->assertSame(0, Subscription::query()->where('organization_id', 'org_sca')->count());
        $this->assertSame('pending', $session->refresh()->status->value);
        $this->getJson('/billing/checkout/'.$session->token.'/status')->assertJsonPath('complete', false);
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
