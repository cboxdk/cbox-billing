<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money P1 (re-review remediation): a hosted checkout for a TIERED plan must charge the tier-aware
 * figure the first invoice bills — NOT the base `price_minor`. `business` is a volume plan whose
 * base price (349 000) differs from its per-seat tier for one seat (12 000); before the fix the
 * checkout charged the base gross while the first invoice billed the tier — a graduated plan could
 * even check out "free". The checkout gross must now equal the first period invoice gross exactly.
 */
class TieredCheckoutPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        // Deterministic gateway so the intent returns the charged amount without live keys.
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway(PaymentResult::succeeded('gw_ref')));
    }

    public function test_a_volume_plans_checkout_charges_the_tier_amount_the_first_invoice_bills(): void
    {
        $org = Organization::query()->create([
            'id' => 'org_tiered', 'name' => 'Tiered', 'billing_email' => 'tiered@example.test', 'billing_country' => 'DK',
        ]);
        ['plaintext' => $token] = ApiToken::issue('tiered-sdk', 'org_tiered');
        $plan = Plan::query()->with('prices')->where('key', 'business')->firstOrFail();

        // Sanity: the base price and the one-seat tier amount really differ — otherwise the test
        // would not distinguish the bug from the fix.
        $this->assertSame(349_000, $plan->priceFor('DKK')->minor());
        $this->assertSame(12_000, $plan->amountFor('DKK', 1)->minor());

        // The first period invoice for a one-seat business subscription (the engine's tier figure,
        // taxed at DK 25%): 12 000 net → 15 000 gross.
        $subscription = app(SubscribesOrganizations::class)->subscribe($org, $plan);
        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());
        $this->assertSame(12_000, $invoice->subtotal_minor);
        $this->assertSame(15_000, $invoice->total_minor);

        // The checkout intent for the same plan charges EXACTLY that gross — the tier amount, not
        // the 349 000 base (whose gross would be 436 250).
        $session = $this->openCheckout('org_tiered', 'business', $token);
        $amount = $this->postJson('/billing/checkout/'.$session->token.'/intent')->assertOk()->json('amount');

        $this->assertSame($invoice->total_minor, $amount['minor']);
        $this->assertSame(15_000, $amount['minor']);
        $this->assertSame('DKK', $amount['currency']);
    }

    private function openCheckout(string $org, string $plan, string $token): BillingSession
    {
        $response = $this->postJson('/api/v1/checkout-sessions', [
            'org' => $org,
            'plan' => $plan,
            'return_url' => 'https://merchant.example/done',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $sessionToken = basename((string) parse_url((string) $response->json('url'), PHP_URL_PATH));
        $session = BillingSession::query()->where('organization_id', $org)->firstOrFail();
        $session->token = $sessionToken;

        return $session;
    }
}
