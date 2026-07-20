<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Dunning\CardUpdate;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Re-review remediation: the PUBLIC / webhook / activation entry points that query mode-scoped
 * data must bootstrap the request's plane from the reference/token they resolve BEFORE any
 * mode-scoped read — a test-plane request must never read or affect the live plane (and vice-versa)
 * for the SAME org id. Also covers the CRITICAL settlement-dedup bug: a rejected (wrong-amount)
 * settlement must NOT consume the settle-once / processed guards, so a corrected retry still applies.
 */
class CrossPlaneEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);

        // The activation heartbeat cuts a SIGNED revocation list, so it needs an issuer key pair.
        $keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $keyPair['privateKey'],
            'billing.licensing.public_key' => $keyPair['publicKey'],
        ]);
    }

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function org(string $id): void
    {
        Organization::query()->withoutGlobalScopes()->firstOrCreate(
            ['id' => $id],
            ['name' => ucfirst($id), 'billing_country' => 'DK'],
        );
    }

    private function invoice(string $number, string $org, BillingMode $mode): Invoice
    {
        return $this->context()->runInMode($mode, fn (): Invoice => Invoice::query()->create([
            'organization_id' => $org, 'seller' => 'seller_x', 'number' => $number, 'currency' => 'DKK',
            'subtotal_minor' => 29_000, 'tax_minor' => 7_250, 'total_minor' => 36_250,
            'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
        ]));
    }

    private function paidUnscoped(string $number): bool
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->where('number', $number)->firstOrFail();

        return $invoice->isPaid();
    }

    private function postSettlement(string $reference, int $amountMinor, string $eventId): TestResponse
    {
        $body = json_encode([
            'event_id' => $eventId,
            'type' => 'payment.settled',
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => 'DKK',
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST', '/webhooks/manual', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET)],
            $body,
        );
    }

    // --- 1. Settlement webhook: plane bootstrap from the reference ----------------------------

    public function test_a_settlement_webhook_pays_only_the_invoice_in_its_own_plane(): void
    {
        $this->org('org_wh');
        $this->invoice('INV-LIVE', 'org_wh', BillingMode::Live);
        $this->invoice('INV-TEST', 'org_wh', BillingMode::Test);

        // A settlement for the TEST invoice number is applied in the test plane only.
        $this->postSettlement('INV-TEST', 36_250, 'evt_test')->assertOk();
        $this->assertTrue($this->paidUnscoped('INV-TEST'));
        $this->assertFalse($this->paidUnscoped('INV-LIVE'));

        // ...and the LIVE settlement pays only the live invoice (both directions).
        $this->postSettlement('INV-LIVE', 36_250, 'evt_live')->assertOk();
        $this->assertTrue($this->paidUnscoped('INV-LIVE'));

        // The settle-once guards landed in their own planes.
        $this->assertSame(1, DB::table('settled_payments')->where('reference', 'INV-TEST')->where('livemode', false)->count());
        $this->assertSame(1, DB::table('settled_payments')->where('reference', 'INV-LIVE')->where('livemode', true)->count());
    }

    // --- 2. Settlement rejection must NOT consume the dedup/settle guards ----------------------

    public function test_a_rejected_settlement_does_not_block_a_corrected_retry(): void
    {
        $this->org('org_retry');
        $this->invoice('INV-RETRY', 'org_retry', BillingMode::Live);

        // A wrong-amount settlement is rejected: nothing paid, and NO settle/processed guard written.
        $this->postSettlement('INV-RETRY', 1, 'evt_wrong')->assertOk()->assertJsonPath('applied', false);
        $this->assertFalse($this->paidUnscoped('INV-RETRY'));
        $this->assertSame(0, DB::table('settled_payments')->where('reference', 'INV-RETRY')->count());
        $this->assertSame(0, DB::table('webhook_processed_events')->where('event_id', 'evt_wrong')->count());
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'invoice.settlement_rejected']);

        // A subsequent CORRECT-amount settlement for the same invoice DOES apply — not dropped as a
        // duplicate.
        $this->postSettlement('INV-RETRY', 36_250, 'evt_right')->assertOk()->assertJsonPath('applied', true);
        $this->assertTrue($this->paidUnscoped('INV-RETRY'));
        $this->assertSame(1, DB::table('settled_payments')->where('reference', 'INV-RETRY')->count());
    }

    // --- 3. Card-updater webhook: plane adopted from the gateway customer ----------------------

    public function test_a_card_update_adopts_the_gateway_customers_plane_in_both_directions(): void
    {
        $this->org('org_ct');
        $this->org('org_cl');

        // A gateway customer that exists ONLY in the test plane, and one ONLY in live.
        $this->context()->runInMode(BillingMode::Test, function (): void {
            GatewayCustomer::query()->create(['organization_id' => 'org_ct', 'gateway' => 'acme', 'gateway_customer_id' => 'cus_test']);
        });
        $this->context()->runInMode(BillingMode::Live, function (): void {
            GatewayCustomer::query()->create(['organization_id' => 'org_cl', 'gateway' => 'acme', 'gateway_customer_id' => 'cus_live']);
        });

        $updater = app(UpdatesCards::class);

        // Ambient LIVE: a card-update for the test-only customer still resolves it (unscoped) and
        // adopts the TEST plane — without the fix the live-scoped lookup would miss it entirely.
        $this->context()->setMode(BillingMode::Live);
        $test = $updater->apply(new CardUpdate('evt_ct', 'acme', 'cus_test', 'pm_1'));
        $this->assertTrue($test->applied);
        $this->assertSame('org_ct', $test->organizationId);

        // Ambient TEST: a card-update for the live-only customer adopts the LIVE plane.
        $this->context()->setMode(BillingMode::Test);
        $live = $updater->apply(new CardUpdate('evt_cl', 'acme', 'cus_live', 'pm_2'));
        $this->assertTrue($live->applied);
        $this->assertSame('org_cl', $live->organizationId);
    }

    // --- 4. License activation: bootstrapped to the deployment's plane -------------------------

    public function test_license_activation_serves_the_deployment_in_its_own_plane(): void
    {
        // A license issued in the TEST plane for a deployment.
        DB::table('issued_licenses')->insert($this->licenseRow('lic_test', 'dep_x', false));

        // The heartbeat carries no credential (ambient LIVE); it must still resolve the test license
        // by bootstrapping to the deployment's plane, not 404 under the live default.
        $this->getJson('/api/v1/license/activate?deployment_id=dep_x')
            ->assertOk()
            ->assertJsonPath('license_id', 'lic_test')
            ->assertJsonPath('deployment_id', 'dep_x');

        // A deployment that exists in neither plane is a generic 404.
        $this->getJson('/api/v1/license/activate?deployment_id=dep_unknown')->assertNotFound();
    }

    // --- 5. Paywall: rendered in the checkout session's plane ----------------------------------

    public function test_the_paywall_renders_in_the_session_tokens_plane(): void
    {
        $this->org('org_pw');
        $organization = Organization::query()->withoutGlobalScopes()->findOrFail('org_pw');
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // LIVE: the org already HAS the `sso` feature (subscribed to Team). If the paywall wrongly
        // read the live plane it would find the feature present and offer no upgrade (no CTA).
        $this->context()->runInMode(BillingMode::Live, function () use ($organization, $team): void {
            app(SubscribesOrganizations::class)->subscribe($organization, $team, 1, 'DKK');
        });

        // TEST: no subscription — the org LACKS `sso`, so the paywall offers the Team upgrade. Its
        // checkout session token names the test plane.
        $token = $this->context()->runInMode(BillingMode::Test, function () use ($organization): string {
            return app(ManagesBillingSessions::class)->openCheckout(
                $organization, Plan::query()->where('key', 'starter')->firstOrFail(), 'DKK', 'https://merchant.example/done',
            )->token;
        });

        // The paywall resolves the token UNSCOPED, verifies the org, and sets the TEST plane BEFORE
        // the presenter reads entitlement state — so it sees the test plane (feature absent → offer +
        // CTA deep-link), never the live plane (where the feature is present → no offer).
        $this->get('/paywall?org=org_pw&feature=sso&session='.$token)
            ->assertOk()
            ->assertSee('/billing/checkout/'.$token, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function licenseRow(string $id, string $deployment, bool $livemode): array
    {
        return [
            'id' => $id,
            'customer_id' => 'cust_1',
            'deployment_id' => $deployment,
            'plan' => 'pro',
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
            'limits' => json_encode([], JSON_THROW_ON_ERROR),
            'licensed_domain' => null,
            'issued_at' => Carbon::now(),
            'not_before' => Carbon::now(),
            'expires_at' => Carbon::now()->addYear(),
            'key' => 'signed.jwt.artifact',
            'livemode' => $livemode,
            'created_at' => Carbon::now(),
        ];
    }
}
