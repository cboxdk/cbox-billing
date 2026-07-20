<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The end-to-end billing flow, exercised through the real services and the HTTP API:
 * subscribe → reserve/commit via the enforcement API → reconcile → invoice (+ tax) → pay
 * → settle via a signed webhook → invoice marked paid. Every step drives an engine
 * contract; only the gateway (which would reach out over a network) is left as the
 * dependency-free manual gateway.
 */
class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);

        // Only the catalog is seeded; the org and its subscription are created by the
        // subscribe service under test.
        $this->seed(CatalogSeeder::class);
    }

    public function test_full_billing_lifecycle_from_subscribe_to_paid(): void
    {
        // --- 1. Subscribe an org to a plan (creates the subscription + wallet grants) ---
        $organization = Organization::query()->create([
            'id' => 'org_acme',
            'name' => 'Acme Internal',
            'billing_email' => 'billing@acme.example',
            'billing_country' => 'DK',
        ]);

        $plan = Plan::query()->where('key', 'team')->firstOrFail();

        // 20 seats: `team` is graduated with the first 10 seats free, so the seat-aware,
        // pricing-model-aware recurring charge is 10 × 0 + 10 × 9 900 = 99 000 DKK (the same
        // figure MRR and the console preview compute — the invoice bills through the engine,
        // never the raw base price).
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan, seats: 20);

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => 'org_acme',
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // A per-org API token authenticates the SDK-facing calls.
        ['plaintext' => $token] = ApiToken::issue('acme-sdk', 'org_acme');
        $auth = ['Authorization' => 'Bearer '.$token];

        // --- 2a. Entitlements the SDK caches to enforce locally ---
        $entitlements = $this->getJson('/api/v1/entitlements/org_acme', $auth);
        $entitlements->assertOk();

        $meters = $entitlements->json('meters');
        $this->assertIsArray($meters);
        $this->assertTrue($meters['api.requests']['enabled']);
        $this->assertSame(1_000_000, $meters['api.requests']['allowance']);
        $this->assertSame('bill', $meters['api.requests']['overage']);

        // --- 2b. Reserve a bucket within allowance, then commit the actual usage ---
        $reserve = $this->postJson('/api/v1/reserve', [
            'org' => 'org_acme',
            'meters' => [['meter' => 'api.requests', 'estimate' => 1_000]],
        ], $auth);

        $reserve->assertOk()->assertJsonPath('outcome', 'allowed');
        $reservationId = $reserve->json('reservation_id');
        $this->assertIsString($reservationId);

        $commit = $this->postJson('/api/v1/commit', [
            'reservation_id' => $reservationId,
            'actuals' => [['meter' => 'api.requests', 'actual' => 800]],
        ], $auth);
        $commit->assertOk()->assertJsonPath('ok', true);

        // The committed usage landed in the durable event log (the metering truth).
        $sum = app(EventLog::class)->sum('org_acme', 'api.requests', 0, (int) (microtime(true) * 1000) + 1);
        $this->assertSame(800, $sum);

        // --- 2c. A denied reserve on a disabled/unknown meter (deny-by-default) ---
        $denied = $this->postJson('/api/v1/reserve', [
            'org' => 'org_acme',
            'meters' => [['meter' => 'nonexistent.meter', 'estimate' => 1]],
        ], $auth);
        $denied->assertOk()->assertJsonPath('outcome', 'denied');

        // --- 2d. Cumulative usage ingest is self-correcting ---
        $usage = $this->postJson('/api/v1/usage', [
            'org' => 'org_acme',
            'entries' => [['meter' => 'events.ingested', 'cumulative' => 1_500, 'seq' => 1]],
        ], $auth);
        $usage->assertOk()->assertJsonPath('ok', true);

        // --- 3. Reconcile durable usage into the ledger ---
        $reconcile = Artisan::call('billing:reconcile-active');
        $this->assertSame(0, $reconcile);

        // --- 4. Invoice the subscription period (composes the tax engine) ---
        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());

        // Team graduated @ 20 seats is 99 000 DKK net (seat-aware, via the engine); DK
        // domestic B2B VAT is 25% → 24 750 tax, 123 750 gross.
        $this->assertSame(99_000, $invoice->subtotal_minor);
        $this->assertSame(24_750, $invoice->tax_minor);
        $this->assertSame(123_750, $invoice->total_minor);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertStringStartsWith('CBOX-DK-', $invoice->number);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id]);

        // --- 5. Pay the invoice through the gateway (manual → pending) ---
        $result = app(PaysInvoices::class)->pay($invoice);
        $this->assertSame(PaymentStatus::Pending, $result->status);
        $this->assertFalse($invoice->refresh()->isPaid());

        // --- 6. Settle via a signed webhook → the ingest marks the invoice paid ---
        $body = json_encode([
            'event_id' => 'evt_1',
            'type' => 'payment.settled',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $webhook = $this->call(
            'POST',
            '/webhooks/manual',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => $signature],
            $body,
        );

        $webhook->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('status', 'applied');

        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame('evt_1', $invoice->gateway_reference);

        // --- Exactly-once: a re-delivery is a no-op, the invoice stays paid ---
        $replay = $this->call(
            'POST',
            '/webhooks/manual',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => $signature],
            $body,
        );
        $replay->assertOk()->assertJsonPath('applied', false);
    }

    public function test_enforcement_api_denies_an_unauthenticated_request(): void
    {
        $this->postJson('/api/v1/reserve', [
            'org' => 'org_acme',
            'meters' => [['meter' => 'api.requests', 'estimate' => 1]],
        ])->assertUnauthorized();
    }

    public function test_org_scoped_token_cannot_act_for_another_org(): void
    {
        Organization::query()->create(['id' => 'org_one', 'name' => 'One', 'billing_country' => 'DK']);
        Organization::query()->create(['id' => 'org_two', 'name' => 'Two', 'billing_country' => 'DK']);

        ['plaintext' => $token] = ApiToken::issue('one-sdk', 'org_one');

        $this->getJson('/api/v1/entitlements/org_two', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }

    public function test_invoice_is_tax_pending_for_an_org_without_an_address(): void
    {
        $organization = Organization::query()->create(['id' => 'org_noaddr', 'name' => 'No Address']);
        $plan = Plan::query()->where('key', 'starter')->firstOrFail();

        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan);

        $quote = app(GeneratesInvoices::class)->quoteFor($subscription);

        $this->assertFalse($quote->isTaxResolved());
    }
}
