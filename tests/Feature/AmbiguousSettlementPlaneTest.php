<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SellerEntity;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P1 BACKSTOP — A REFERENCE-ONLY SETTLEMENT MUST NOT DEFAULT TO PRODUCTION.
 *
 * Cloned and config-fallback sellers now number their legal documents per plane, so two planes no
 * longer mint the same invoice number by themselves ({@see CrossPlaneDocumentNumberingTest}). An
 * operator can still hand-author the same prefix in two planes, though — and a settlement payload
 * that carries NOTHING but that number is then genuinely ambiguous.
 *
 * The resolver used to break the tie by preferring the AMBIENT plane, which for the unauthenticated
 * webhook route is production: an event meant for a sandbox settled PRODUCTION's identically-numbered
 * invoice. It now FAILS CLOSED — the payload is refused and neither invoice moves.
 *
 * The refusal is byte-identical to a signature rejection (same status, same body), so it cannot be
 * used as an oracle for which references exist or how many planes hold them.
 */
class AmbiguousSettlementPlaneTest extends TestCase
{
    use RefreshDatabase;

    /** The number an operator made collide by authoring the same prefix in both planes. */
    private const COLLIDING = 'CBOX-DK-2026-00001';

    /** A number that exists in the SANDBOX only — unambiguous, so it must still settle. */
    private const SANDBOX_ONLY = 'CBOX-DK-2026-00042';

    private const GLOBAL_SECRET = 'whsec_global_prod_secret';

    private const SANDBOX_DB_SECRET = 'whsec_sandbox_db_secret';

    private const SANDBOX = 'ci-clone';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
        config(['billing-stripe.webhook_secret' => self::GLOBAL_SECRET]);

        app(CreatesEnvironments::class)->create(
            key: self::SANDBOX,
            cloneFrom: Environment::query()->where('key', Environment::PRODUCTION)->firstOrFail(),
        );

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
     * An OPEN invoice in `$plane`, its org holding gateway customer `$customer` and its settlement
     * arriving as gateway object `$object` (pre-recorded, as an intent created before settlement is).
     */
    private function seedInvoice(string $plane, string $seller, string $number, string $object, ?string $customer = null): void
    {
        $this->inPlane($plane, function () use ($plane, $seller, $number, $object, $customer): void {
            $org = 'org_'.$plane;

            Organization::query()->firstOrCreate(['id' => $org], ['name' => $org, 'billing_country' => 'DK']);

            if ($customer !== null) {
                GatewayCustomer::query()->firstOrCreate([
                    'organization_id' => $org, 'gateway' => 'stripe', 'gateway_customer_id' => $customer,
                ]);
            }

            Invoice::query()->create([
                'organization_id' => $org, 'seller' => $seller, 'number' => $number, 'currency' => 'EUR',
                'subtotal_minor' => 12_500, 'tax_minor' => 0, 'total_minor' => 12_500,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
                'gateway_reference' => $object,
            ]);
        });
    }

    /**
     * The hand-authored collision: the sandbox's seller is edited back to production's prefix (which
     * the cloner deliberately no longer does), and both planes then hold {@see COLLIDING}.
     */
    private function seedCollision(): void
    {
        $sandboxSeller = SellerEntity::query()->withoutGlobalScopes()
            ->where('environment', self::SANDBOX)->first();

        if ($sandboxSeller instanceof SellerEntity) {
            $sandboxSeller->forceFill(['invoice_prefix' => 'CBOX-DK'])->save();
        }

        $this->seedInvoice(Environment::PRODUCTION, 'seller_x', self::COLLIDING, 'pi_live_1', 'cus_live_1');
        $this->seedInvoice(self::SANDBOX, self::SANDBOX.'__seller_x', self::COLLIDING, 'pi_test_1', 'cus_test_1');
    }

    /** A Stripe `payment_intent.succeeded`; an empty `$object`/`$customer` is simply omitted. */
    private function postStripe(string $reference, string $object, string $customer, string $secret, string $eventId): TestResponse
    {
        $data = ['object' => 'payment_intent', 'amount' => 12_500, 'currency' => 'eur', 'status' => 'succeeded',
            'metadata' => ['reference' => $reference]];

        if ($object !== '') {
            $data['id'] = $object;
        }

        if ($customer !== '') {
            $data['customer'] = $customer;
        }

        $body = (string) json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => $data],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call(
            'POST', '/webhooks/stripe', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $body,
        );
    }

    private function paidIn(string $plane, string $number): bool
    {
        $invoice = Invoice::query()->withoutGlobalScopes()
            ->where('environment', $plane)->where('number', $number)->firstOrFail();

        return $invoice->isPaid();
    }

    /**
     * THE FIX. A payload whose only plane signal is a number held by TWO planes is refused, and
     * NEITHER invoice settles. Pre-fix the ambient-plane preference settled production's.
     */
    public function test_a_reference_only_payload_settles_neither_plane_when_the_number_collides(): void
    {
        $this->seedCollision();

        $this->postStripe(self::COLLIDING, '', '', self::GLOBAL_SECRET, 'evt_ref_only')->assertStatus(400);

        $this->assertFalse($this->paidIn(Environment::PRODUCTION, self::COLLIDING), 'production must not settle');
        $this->assertFalse($this->paidIn(self::SANDBOX, self::COLLIDING), 'the sandbox must not settle');
        $this->assertSame(0, DB::table('settled_payments')->where('reference', self::COLLIDING)->count());
    }

    /** The same refusal for a payload signed with the SANDBOX's secret — no plane, no settlement. */
    public function test_a_reference_only_payload_signed_by_the_sandbox_is_refused_too(): void
    {
        $this->seedCollision();

        $this->postStripe(self::COLLIDING, '', '', self::SANDBOX_DB_SECRET, 'evt_ref_only_sbx')->assertStatus(400);

        $this->assertFalse($this->paidIn(Environment::PRODUCTION, self::COLLIDING));
        $this->assertFalse($this->paidIn(self::SANDBOX, self::COLLIDING));
    }

    /**
     * NO ENUMERATION ORACLE. A prober holds no signing secret, so every request it can make is
     * unauthentic — and an unauthentic request gets the SAME status and body whether its reference
     * collides across planes, exists in one plane, or exists nowhere. The route therefore answers no
     * question about what references exist or how many planes hold them.
     */
    public function test_an_unsigned_prober_cannot_tell_a_colliding_reference_from_any_other(): void
    {
        $this->seedCollision();
        $this->seedInvoice(self::SANDBOX, self::SANDBOX.'__seller_x', self::SANDBOX_ONLY, 'pi_only_1');

        $colliding = $this->postStripe(self::COLLIDING, '', '', 'whsec_forged', 'evt_oracle_a');
        $single = $this->postStripe(self::SANDBOX_ONLY, '', '', 'whsec_forged', 'evt_oracle_b');
        $absent = $this->postStripe('CBOX-DK-2026-88888', '', '', 'whsec_forged', 'evt_oracle_c');

        $this->assertSame(400, $colliding->getStatusCode());
        $this->assertSame($colliding->getStatusCode(), $single->getStatusCode());
        $this->assertSame($colliding->getContent(), $single->getContent());
        $this->assertSame($colliding->getStatusCode(), $absent->getStatusCode());
        $this->assertSame($colliding->getContent(), $absent->getContent());
    }

    /**
     * Even a VALIDLY SIGNED ambiguous payload — which only a secret holder can mint — is answered
     * with a rejection the route already emits (the verifier's own missing-signature wording), never
     * a bespoke "that reference is ambiguous" marker.
     */
    public function test_a_signed_ambiguous_payload_is_refused_in_the_verifiers_own_words(): void
    {
        $this->seedCollision();

        $signed = $this->postStripe(self::COLLIDING, '', '', self::GLOBAL_SECRET, 'evt_signed_ambiguous');

        $signed->assertStatus(400);

        $error = $signed->json('error');
        $this->assertIsString($error);
        $this->assertStringNotContainsStringIgnoringCase('plane', $error);
        $this->assertStringNotContainsStringIgnoringCase('ambiguous', $error);
        $this->assertStringNotContainsString(self::COLLIDING, $error);
    }

    /** A STRONG signal still decides: the gateway object id settles its own plane, as before. */
    public function test_a_gateway_object_id_still_settles_the_right_plane(): void
    {
        $this->seedCollision();

        $this->postStripe(self::COLLIDING, 'pi_test_1', 'cus_test_1', self::SANDBOX_DB_SECRET, 'evt_obj')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn(self::SANDBOX, self::COLLIDING));
        $this->assertFalse($this->paidIn(Environment::PRODUCTION, self::COLLIDING));
    }

    /** So does the gateway CUSTOMER handle alone (cloning never copies the mapping table). */
    public function test_the_gateway_customer_still_settles_the_right_plane(): void
    {
        $this->seedCollision();

        $this->postStripe(self::COLLIDING, 'pi_never_seen', 'cus_live_1', self::GLOBAL_SECRET, 'evt_cus')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn(Environment::PRODUCTION, self::COLLIDING));
        $this->assertFalse($this->paidIn(self::SANDBOX, self::COLLIDING));
    }

    /**
     * AND THE HAPPY PATH IS UNCHANGED: a reference-only payload whose number exists in exactly ONE
     * plane still resolves to that plane and settles there — the refusal is scoped to ambiguity.
     */
    public function test_an_unambiguous_reference_only_payload_still_settles(): void
    {
        $this->seedInvoice(self::SANDBOX, self::SANDBOX.'__seller_x', self::SANDBOX_ONLY, 'pi_only_1');

        $this->postStripe(self::SANDBOX_ONLY, '', '', self::SANDBOX_DB_SECRET, 'evt_unambiguous')
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertTrue($this->paidIn(self::SANDBOX, self::SANDBOX_ONLY));
    }
}
