<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\PlaneDocumentPrefix;
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
 * P1 — WEBHOOK PLANE COLLISION ON THE INVOICE NUMBER.
 *
 * The settlement webhook must resolve its owning plane BEFORE the signature is verified (so the
 * plane-aware verifier picks the right secret). That resolution used to key off the invoice `number`
 * ALONE, unscoped — but invoice numbers are unique only per `(seller, number)`, so the same number
 * can exist in production AND in a sandbox, and an unscoped `where('number', …)->first()` picked
 * whichever row came back first: a sandbox settlement could be verified against, and applied to, the
 * PRODUCTION invoice, or a valid sandbox event could be rejected against the wrong plane's secret.
 *
 * Cloning is no longer what produces that collision — a cloned seller now numbers under a
 * plane-distinct prefix ({@see PlaneDocumentPrefix}) — so the colliding
 * invoices here are seeded directly, which is the shape an operator can still hand-author. The
 * reference-only half of that case is covered by {@see AmbiguousSettlementPlaneTest}; this file
 * covers the strong gateway signals resolving the right plane regardless.
 *
 * The plane is now resolved from a GLOBALLY-UNIQUE gateway signal first — the gateway object id
 * (`pi_…`, recorded on the checkout session / settled invoice / dunning retry attempt) and then the
 * gateway customer handle (`cus_…`, whose mapping table environment cloning does not copy) — and the
 * number is consulted only when it is unambiguous.
 *
 * Every test here sets up the SAME invoice number in both planes, so the number carries no
 * information at all and only the gateway signal can decide.
 */
class WebhookPlaneCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** The colliding invoice number — the same in production and in the sandbox. */
    private const NUMBER = 'CBOX-DK-2026-00001';

    private const GLOBAL_SECRET = 'whsec_global_prod_secret';

    private const SANDBOX_DB_SECRET = 'whsec_sandbox_db_secret';

    private const SANDBOX = 'ci-clone';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
        config(['billing-stripe.webhook_secret' => self::GLOBAL_SECRET]);

        // A named sandbox CLONED from production — which is exactly what duplicates the seller
        // entities (and their invoice prefixes) and so makes the number collide across planes.
        app(CreatesEnvironments::class)->create(
            key: self::SANDBOX,
            cloneFrom: Environment::query()->where('key', 'production')->firstOrFail(),
        );

        // The sandbox configures its OWN Stripe webhook secret; production keeps the global one.
        app(EnvironmentGatewayStore::class)->put(
            Environment::query()->where('key', self::SANDBOX)->firstOrFail(),
            secret: 'sk_test_x', publishable: null, webhookSecret: self::SANDBOX_DB_SECRET,
        );
    }

    /** @template T @param callable(): T $work @return T */
    private function inPlane(string $key, callable $work): mixed
    {
        return app(BillingContext::class)->runInEnvironment(
            Environment::query()->where('key', $key)->firstOrFail(),
            $work,
        );
    }

    /**
     * An OPEN invoice numbered {@see NUMBER} in `$plane`, whose organization holds the gateway
     * customer `$customer` and whose settlement will arrive as gateway object `$object`. The object
     * id is pre-recorded on the invoice, mirroring an intent created for it before settlement.
     *
     * `$seller` is what makes the collision reproducible rather than hypothetical: the two planes
     * hold DIFFERENT seller ids (a cloned seller is stored under `<plane>__<id>`) issuing the
     * byte-identical number `CBOX-DK-2026-00001`, which the `(seller, number)` unique index allows.
     * The cloner no longer creates that state by itself, but an operator authoring the same invoice
     * prefix in two planes still can.
     */
    private function seedPlane(string $plane, string $seller, string $org, string $customer, string $object): void
    {
        $this->inPlane($plane, function () use ($seller, $org, $customer, $object): void {
            Organization::query()->create(['id' => $org, 'name' => $org, 'billing_country' => 'DK']);
            GatewayCustomer::query()->create([
                'organization_id' => $org, 'gateway' => 'stripe', 'gateway_customer_id' => $customer,
            ]);
            Invoice::query()->create([
                'organization_id' => $org, 'seller' => $seller, 'number' => self::NUMBER, 'currency' => 'EUR',
                'subtotal_minor' => 12_500, 'tax_minor' => 0, 'total_minor' => 12_500,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
                'gateway_reference' => $object,
            ]);
        });
    }

    /** Both planes hold an invoice with the SAME number; only the gateway ids differ. */
    private function seedCollision(): void
    {
        $this->seedPlane('production', 'seller_x', 'org_prod', 'cus_live_1', 'pi_live_1');
        $this->seedPlane(self::SANDBOX, self::SANDBOX.'__seller_x', 'org_sbx', 'cus_test_1', 'pi_test_1');
    }

    /** A Stripe `payment_intent.succeeded` for the colliding number, carrying `$object`/`$customer`. */
    private function postStripe(string $object, string $customer, string $secret, string $eventId): TestResponse
    {
        $body = (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => $object, 'object' => 'payment_intent', 'amount' => 12_500, 'currency' => 'eur',
                'status' => 'succeeded', 'customer' => $customer,
                'metadata' => ['reference' => self::NUMBER],
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

    private function paidIn(string $plane): bool
    {
        $invoice = Invoice::query()->withoutGlobalScopes()
            ->where('environment', $plane)->where('number', self::NUMBER)->firstOrFail();

        return $invoice->isPaid();
    }

    /**
     * A webhook carrying the SANDBOX invoice's gateway reference resolves the SANDBOX plane: it
     * verifies with the sandbox's own secret, applies to the sandbox invoice, and leaves the
     * identically-numbered production invoice untouched.
     */
    public function test_a_sandbox_gateway_reference_settles_the_sandbox_invoice_only(): void
    {
        $this->seedCollision();

        $this->postStripe('pi_test_1', 'cus_test_1', self::SANDBOX_DB_SECRET, 'evt_sbx')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn(self::SANDBOX), 'the sandbox invoice must be settled');
        $this->assertFalse($this->paidIn('production'), 'the production invoice must be untouched');

        $this->assertSame(1, DB::table('settled_payments')
            ->where('reference', self::NUMBER)->where('environment', self::SANDBOX)->count());
        $this->assertSame(0, DB::table('settled_payments')
            ->where('reference', self::NUMBER)->where('environment', 'production')->count());
    }

    /** The MIRROR: the production gateway reference settles production and never the sandbox. */
    public function test_a_production_gateway_reference_settles_the_production_invoice_only(): void
    {
        $this->seedCollision();

        $this->postStripe('pi_live_1', 'cus_live_1', self::GLOBAL_SECRET, 'evt_prod')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn('production'), 'the production invoice must be settled');
        $this->assertFalse($this->paidIn(self::SANDBOX), 'the sandbox invoice must be untouched');

        $this->assertSame(1, DB::table('settled_payments')
            ->where('reference', self::NUMBER)->where('environment', 'production')->count());
        $this->assertSame(0, DB::table('settled_payments')
            ->where('reference', self::NUMBER)->where('environment', self::SANDBOX)->count());
    }

    /**
     * The secret half of the same collision: a sandbox-targeted payload signed with the
     * GLOBAL/production secret must not authenticate, because the sandbox plane (and therefore the
     * sandbox's own secret) is chosen from the gateway signal before verification runs. Neither
     * invoice moves.
     */
    public function test_the_global_secret_cannot_authenticate_a_sandbox_gateway_reference(): void
    {
        $this->seedCollision();

        $this->postStripe('pi_test_1', 'cus_test_1', self::GLOBAL_SECRET, 'evt_forged')->assertStatus(400);

        $this->assertFalse($this->paidIn(self::SANDBOX));
        $this->assertFalse($this->paidIn('production'));
        $this->assertSame(0, DB::table('settled_payments')->where('reference', self::NUMBER)->count());
    }

    /**
     * The gateway CUSTOMER alone is enough: a first settlement whose intent was never pre-recorded on
     * the invoice still resolves by `cus_…`, whose mapping table environment cloning does not copy.
     */
    public function test_the_gateway_customer_alone_resolves_the_sandbox_plane(): void
    {
        $this->seedCollision();

        // An object id no row has ever seen — only the customer handle can decide the plane.
        $this->postStripe('pi_unseen_9', 'cus_test_1', self::SANDBOX_DB_SECRET, 'evt_cus')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn(self::SANDBOX));
        $this->assertFalse($this->paidIn('production'));
    }

    /**
     * DENY-BY-DEFAULT on a genuinely ambiguous payload: the number collides and the body carries no
     * gateway object or customer we know, so the resolver refuses to name a plane at all and the
     * payload is rejected — it is applied to NEITHER plane, whichever secret signed it.
     */
    public function test_an_unresolvable_payload_never_crosses_into_another_plane(): void
    {
        $this->seedCollision();

        $this->postStripe('pi_unknown', 'cus_unknown', self::SANDBOX_DB_SECRET, 'evt_ambiguous')
            ->assertStatus(400);

        $this->assertFalse($this->paidIn(self::SANDBOX));
        $this->assertFalse($this->paidIn('production'));
    }
}
