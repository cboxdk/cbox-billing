<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Finding 1 (P1) — the settlement webhook controller must resolve the reference's OWNING plane and
 * set it BEFORE the signature is verified, so the plane-aware verifier proves the signature against
 * the CORRECT plane's secret.
 *
 * A sandbox that configured its own DB webhook secret verifies a webhook signed with THAT secret and
 * applies it in the sandbox; a webhook aimed at the same sandbox reference but signed with the
 * global/production secret does NOT authenticate (the sandbox's secret differs) — closing the hole
 * where the global secret could authenticate a payload later applied to a sandbox reference. A
 * production reference (no DB secret) still verifies against the global secret (BC).
 */
class WebhookPlaneSecretVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const GLOBAL_SECRET = 'whsec_global_prod_secret';

    private const SANDBOX_DB_SECRET = 'whsec_sandbox_db_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);

        // The global env-var Stripe webhook secret (production's single-plane default).
        config(['billing-stripe.webhook_secret' => self::GLOBAL_SECRET]);

        // The sandbox plane configures its OWN DB webhook secret (console/API-driven Stripe setup).
        $sandbox = Environment::query()->where('key', 'sandbox')->firstOrFail();
        app(EnvironmentGatewayStore::class)->put($sandbox, secret: 'sk_test_x', publishable: null, webhookSecret: self::SANDBOX_DB_SECRET);
    }

    private function invoice(string $number, string $key): void
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        app(BillingContext::class)->runInEnvironment($environment, function () use ($number): void {
            Organization::query()->firstOrCreate(['id' => 'org_wh'], ['name' => 'WH', 'billing_country' => 'DK']);

            Invoice::query()->create([
                'organization_id' => 'org_wh', 'seller' => 'seller_x', 'number' => $number, 'currency' => 'EUR',
                'subtotal_minor' => 12_500, 'tax_minor' => 0, 'total_minor' => 12_500,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
            ]);
        });
    }

    private function paidUnscoped(string $number): bool
    {
        return Invoice::query()->withoutGlobalScopes()->where('number', $number)->firstOrFail()->isPaid();
    }

    /** A Stripe-shaped `payment_intent.succeeded` body signed (Stripe's `t=,v1=` scheme) with `$secret`. */
    private function postStripe(string $reference, string $secret, string $eventId): TestResponse
    {
        $body = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_'.$eventId, 'object' => 'payment_intent', 'amount' => 12_500,
                'currency' => 'eur', 'status' => 'succeeded', 'metadata' => ['reference' => $reference],
            ]],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call(
            'POST', '/webhooks/stripe', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $body,
        );
    }

    public function test_a_sandbox_reference_verifies_with_the_sandboxs_own_db_secret(): void
    {
        $this->invoice('INV-SB', 'sandbox');

        // Signed with the sandbox's DB secret → the controller resolves the sandbox plane from the
        // reference, verifies against the sandbox secret, and applies the settlement in the sandbox.
        $this->postStripe('INV-SB', self::SANDBOX_DB_SECRET, 'evt_sb_ok')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidUnscoped('INV-SB'));
        $this->assertSame(1, DB::table('settled_payments')->where('reference', 'INV-SB')->where('environment', 'sandbox')->count());
    }

    public function test_the_global_secret_does_not_authenticate_a_sandbox_reference(): void
    {
        $this->invoice('INV-SB2', 'sandbox');

        // Same sandbox reference, but signed with the GLOBAL/production secret: because the controller
        // sets the sandbox plane before verifying, the sandbox's own (differing) secret is used — so
        // the global-signed payload fails verification and is rejected. Nothing is applied.
        $this->postStripe('INV-SB2', self::GLOBAL_SECRET, 'evt_sb_forged')
            ->assertStatus(400);

        $this->assertFalse($this->paidUnscoped('INV-SB2'));
        $this->assertSame(0, DB::table('settled_payments')->where('reference', 'INV-SB2')->count());
    }

    public function test_a_production_reference_still_verifies_with_the_global_secret(): void
    {
        $this->invoice('INV-PROD', 'production');

        // Production carries no DB secret → the global env-var secret verifies and applies (BC).
        $this->postStripe('INV-PROD', self::GLOBAL_SECRET, 'evt_prod_ok')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidUnscoped('INV-PROD'));
        $this->assertSame(1, DB::table('settled_payments')->where('reference', 'INV-PROD')->where('environment', 'production')->count());
    }

    private function gatewayCustomer(string $customerId, string $key): void
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        app(BillingContext::class)->runInEnvironment($environment, function () use ($customerId): void {
            Organization::query()->firstOrCreate(['id' => 'org_cu'], ['name' => 'CU', 'billing_country' => 'DK']);
            GatewayCustomer::query()->create(['organization_id' => 'org_cu', 'gateway' => 'stripe', 'gateway_customer_id' => $customerId]);
        });
    }

    /** A Stripe-shaped `payment_method.automatically_updated` body signed with `$secret`. */
    private function postCardUpdate(string $customerId, string $secret, string $eventId): TestResponse
    {
        $body = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_method.automatically_updated',
            'data' => ['object' => [
                'id' => 'pm_'.$eventId, 'object' => 'payment_method', 'customer' => $customerId,
                'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ]],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call(
            'POST', '/webhooks/stripe/payment-method', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $body,
        );
    }

    public function test_a_card_update_verifies_with_the_sandboxs_own_db_secret(): void
    {
        $this->gatewayCustomer('cus_sb', 'sandbox');

        // The card-update account resolves to the sandbox plane, so verification uses the sandbox DB
        // secret and the recovery applies in the sandbox.
        $this->postCardUpdate('cus_sb', self::SANDBOX_DB_SECRET, 'evt_cu_ok')
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('organization', 'org_cu');
    }

    public function test_the_global_secret_does_not_authenticate_a_sandbox_card_update(): void
    {
        $this->gatewayCustomer('cus_sb2', 'sandbox');

        // Signed with the global secret but aimed at a sandbox account → rejected (the sandbox's own
        // secret is used for verification).
        $this->postCardUpdate('cus_sb2', self::GLOBAL_SECRET, 'evt_cu_forged')
            ->assertStatus(400);
    }
}
