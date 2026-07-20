<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Mode\LivemodeScope;
use App\Billing\Payments\DatabaseGatewayCustomerResolver;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\BillingSession;
use App\Models\GatewayCustomer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\Subscription;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HP1 — the test/live isolation hole on the PUBLIC token surfaces (hosted checkout/portal
 * sessions, CPQ quotes, and the gateway customer vault). The public `web`/token routes carry
 * no credential, so the ambient billing mode defaults to LIVE; without a plane column on
 * these rows a TEST token resolved against LIVE-scoped data (or provisioned a live
 * subscription), and a TEST setup-intent poisoned the LIVE `gateway_customers` mapping.
 *
 * The fix stamps `livemode` on each surface, mixes in `BelongsToMode`, and makes the public
 * controllers resolve the token FIRST, read its `livemode`, and set the request's plane from
 * it before any org/plan/subscription/gateway query — so a test token only ever sees/acts on
 * its own plane, and the gateway vault keys on `(organization, gateway, livemode)`.
 */
class TestLiveTokenIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        // Each test explicitly names the plane it acts in; start from the live default.
        app(BillingContext::class)->setMode(BillingMode::Live);
    }

    private function makeOrg(string $id): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);
    }

    private function inTestPlane(callable $fn): mixed
    {
        return app(BillingContext::class)->runInMode(BillingMode::Test, $fn);
    }

    public function test_a_test_portal_session_resolves_only_test_plane_data(): void
    {
        // Live plane: an org on Starter.
        $liveOrg = $this->makeOrg('iso_live');
        app(SubscribesOrganizations::class)
            ->subscribe($liveOrg, Plan::query()->where('key', 'starter')->firstOrFail());

        // Test plane: a distinct org on Business, plus a TEST portal session for it.
        $testSession = $this->inTestPlane(function (): BillingSession {
            $testOrg = $this->makeOrg('iso_test');
            app(SubscribesOrganizations::class)
                ->subscribe($testOrg, Plan::query()->where('key', 'business')->firstOrFail());

            return app(ManagesBillingSessions::class)->openPortal($testOrg, 'https://merchant.example/account');
        });

        $this->assertFalse($testSession->livemode);

        // The public portal route runs in the default LIVE plane. The test org has NO live
        // subscription, so were the request to resolve in the live plane it would show the
        // no-subscription state; resolving the TEST token flips the request to the test plane
        // and surfaces the test org's Business subscription.
        $this->get('/billing/portal/'.$testSession->token)
            ->assertOk()
            ->assertSee('Business')
            ->assertSee('Change plan');

        // The request ended in the test plane, set from the token (not the ambient default).
        $this->assertTrue(app(BillingContext::class)->isTest());

        // The live org's Starter subscription never crossed into the test plane.
        $this->assertSame(0, Subscription::query()->withoutGlobalScope(LivemodeScope::class)
            ->where('organization_id', 'iso_test')->where('livemode', true)->count());
        $this->assertSame(0, Subscription::query()->withoutGlobalScope(LivemodeScope::class)
            ->where('organization_id', 'iso_live')->where('livemode', false)->count());
    }

    public function test_a_test_quote_token_resolves_in_the_test_plane_and_provisions_a_test_subscription(): void
    {
        $plan = Plan::query()->where('key', 'starter')->firstOrFail();

        // A test-plane quote, sent, with an order-form token, for a test-plane org.
        $this->inTestPlane(function () use ($plan): void {
            $org = $this->makeOrg('q_iso');
            $quote = Quote::query()->create([
                'number' => 'Q-ISO01', 'organization_id' => $org->id, 'status' => QuoteStatus::Sent,
                'currency' => 'DKK', 'term_count' => 12, 'term_unit' => 'month', 'billing_interval' => 'monthly',
                'token_hash' => Quote::hashToken('tok-testplane'), 'sent_at' => Carbon::now(), 'valid_until' => Carbon::now()->addDays(14),
            ]);
            $quote->lines()->create(['sort_order' => 0, 'type' => QuoteLineType::Plan, 'plan_id' => $plan->id, 'quantity' => 1, 'recurring' => true]);
        });

        // A LIVE-plane query cannot see the test quote (plane-scoped); only a scope-bypass can.
        $hash = Quote::hashToken('tok-testplane');
        $this->assertNull(Quote::query()->where('token_hash', $hash)->first());
        $this->assertNotNull(Quote::query()->withoutGlobalScope(LivemodeScope::class)->where('token_hash', $hash)->first());

        // The public order form (default LIVE) resolves the test token, flips to the test
        // plane, and renders the quote.
        $this->get('/quote/tok-testplane')->assertOk()->assertSee('Q-ISO01');
        $this->assertTrue(app(BillingContext::class)->isTest());

        // Accepting provisions a subscription in the TEST plane (livemode=false), never live.
        $this->post('/quote/tok-testplane/accept', [
            'signer_name' => 'Test Signer', 'signer_email' => 'signer@q.example', 'agree' => '1',
        ])->assertRedirect(route('quote.show', 'tok-testplane'));

        $quote = Quote::query()->withoutGlobalScope(LivemodeScope::class)->where('token_hash', $hash)->firstOrFail();
        $this->assertNotNull($quote->subscription_id);

        $subscription = Subscription::query()
            ->withoutGlobalScope(LivemodeScope::class)
            ->findOrFail($quote->subscription_id);
        $this->assertFalse((bool) $subscription->livemode);
        // And no LIVE subscription leaked into the live plane for this org (the accept ran in
        // the test plane, so the only subscription is livemode=false).
        $this->assertSame(0, Subscription::query()->withoutGlobalScope(LivemodeScope::class)
            ->where('organization_id', 'q_iso')->where('livemode', true)->count());
    }

    public function test_a_test_setup_intent_creates_a_livemode_false_gateway_mapping_without_colliding_with_live(): void
    {
        $org = $this->makeOrg('gc_iso');

        // Same gateway name in both planes, so the ONLY distinguishing dimension is livemode —
        // isolating the (organization_id, gateway, livemode) uniqueness change.
        $gateway = new FakePaymentGateway(PaymentResult::succeeded('gw'), null);
        $resolver = new DatabaseGatewayCustomerResolver($gateway);

        // Live plane: mint the live vault mapping.
        $resolver->resolve($org);
        $liveRow = GatewayCustomer::query()->where('organization_id', 'gc_iso')->firstOrFail();
        $this->assertTrue($liveRow->livemode);

        // Test plane: a test setup-intent for the SAME org + gateway mints a SEPARATE row
        // (livemode=false). Before the fix the unique(organization_id, gateway) key would have
        // made this collide with — and overwrite / read — the live mapping.
        $this->inTestPlane(fn (): string => $resolver->resolve($org));

        // Two rows now coexist for the same (organization, gateway), one per plane.
        $rows = GatewayCustomer::query()->withoutGlobalScope(LivemodeScope::class)
            ->where('organization_id', 'gc_iso')->where('gateway', $gateway->name())->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([true, false], $rows->pluck('livemode')->map(static fn ($v): bool => (bool) $v)->all());

        // The live row is untouched — same id, still livemode=true.
        $this->assertTrue($liveRow->fresh()->livemode);

        // Re-resolving in each plane is idempotent — it finds the plane's own row, minting no
        // third row.
        $resolver->resolve($org);
        $this->inTestPlane(fn (): string => $resolver->resolve($org));
        $this->assertSame(2, GatewayCustomer::query()->withoutGlobalScope(LivemodeScope::class)
            ->where('organization_id', 'gc_iso')->count());
    }

    public function test_a_live_checkout_session_still_activates_in_the_live_plane(): void
    {
        // A guard that the default (live) plane is unchanged: a live session is livemode=true.
        $org = $this->makeOrg('live_guard');
        $session = app(ManagesBillingSessions::class)->openCheckout(
            $org,
            Plan::query()->where('key', 'starter')->firstOrFail(),
            null,
            'https://merchant.example/done',
        );

        $this->assertTrue($session->livemode);
        $this->get('/billing/checkout/'.$session->token)->assertOk();
        $this->assertFalse(app(BillingContext::class)->isTest());

        // Sanity: a manually-created active subscription in test mode stays invisible to live.
        $this->inTestPlane(function (): void {
            Subscription::query()->create([
                'organization_id' => 'live_guard', 'plan_id' => Plan::query()->where('key', 'starter')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active, 'seats' => 1,
            ]);
        });
        $this->assertSame(0, Subscription::query()->where('organization_id', 'live_guard')->count());
    }
}
