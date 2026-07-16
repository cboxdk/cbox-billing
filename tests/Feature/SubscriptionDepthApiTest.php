<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Subscription-management depth (ADR-0012): pause/resume, seat-quantity changes with
 * preview-equals-charge proration, aligned vs independent add-ons, and deferred
 * (change-at-period-end) plan changes distinct from immediate ones — driven through the
 * real `/api/v1` HTTP surface and the engine.
 */
class SubscriptionDepthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** @return array<string, string> */
    private function subscribed(string $org, string $plan = 'team', int $seats = 1): array
    {
        Organization::query()->create([
            'id' => $org,
            'name' => ucfirst($org),
            'billing_email' => $org.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/subscriptions', ['org' => $org, 'plan' => $plan, 'seats' => $seats], $auth)
            ->assertCreated();

        return $auth;
    }

    private function walletBalance(string $org, string $denomination = 'credit'): int
    {
        return app(Wallet::class)->balance(
            $org,
            Pools::included(),
            Denomination::unit($denomination),
            (int) (Carbon::now()->getTimestamp() * 1000),
        );
    }

    public function test_pause_suspends_metering_and_resume_restores_it(): void
    {
        $auth = $this->subscribed('org_pause', 'team');

        // Entitled while active.
        $this->assertNotNull(app(MeterPolicyResolver::class)->resolve('org_pause', 'api.requests'));

        $this->postJson('/api/v1/subscriptions/org_pause/pause', [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('paused', true)
            ->assertJsonPath('renews_at', null);

        // Deny-by-default: a paused subscription resolves no policy, so metering stops.
        $this->assertNull(app(MeterPolicyResolver::class)->resolve('org_pause', 'api.requests'));

        $this->postJson('/api/v1/subscriptions/org_pause/resume', [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused', false);

        $this->assertNotNull(app(MeterPolicyResolver::class)->resolve('org_pause', 'api.requests'));
    }

    public function test_quantity_change_prorates_and_preview_equals_charge(): void
    {
        $auth = $this->subscribed('org_qty', 'team', seats: 2);

        // Team is 124 000 minor/seat in DKK; going 2 -> 4 adds two seats (248 000) prorated
        // over the days still to run, so the charge is positive but below the full delta.
        $preview = $this->postJson('/api/v1/subscriptions/org_qty/quantity', [
            'seats' => 4,
            'preview' => true,
        ], $auth);

        $preview->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('from_seats', 2)
            ->assertJsonPath('to_seats', 4)
            ->assertJsonPath('currency', 'DKK');

        $dueNow = $preview->json('due_now_minor');
        $this->assertIsInt($dueNow);
        $this->assertGreaterThan(0, $dueNow);
        $this->assertLessThan(248_000, $dueNow);

        // Preview does not mutate the seat count.
        $this->getJson('/api/v1/subscriptions/org_qty', $auth)->assertJsonPath('seats', 2);

        // Applying charges the identical prorated amount (preview == charge).
        $applied = $this->postJson('/api/v1/subscriptions/org_qty/quantity', ['seats' => 4], $auth);
        $applied->assertOk()->assertJsonPath('applied', true);
        $this->assertSame($dueNow, $applied->json('due_now_minor'));

        $this->getJson('/api/v1/subscriptions/org_qty', $auth)->assertJsonPath('seats', 4);
    }

    public function test_per_seat_grants_scale_with_the_seat_quantity(): void
    {
        // A plan whose included allotment is granted PER SEAT (GrantKind::PerSeat).
        $product = Product::query()->firstOrFail();
        $plan = Plan::query()->create([
            'product_id' => $product->id,
            'key' => 'seatly',
            'name' => 'Seatly',
            'interval' => 'month',
            'active' => true,
        ]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => 10_000]);
        PlanCreditGrant::query()->create([
            'plan_id' => $plan->id,
            'pool' => Pools::INCLUDED,
            'kind' => GrantKind::PerSeat,
            'cadence' => GrantCadence::Monthly,
            'amount' => 1_000,
            'denomination' => 'credit',
        ]);

        $organization = Organization::query()->create([
            'id' => 'org_seats', 'name' => 'Seats', 'billing_email' => 's@example.test', 'billing_country' => 'DK',
        ]);
        ['plaintext' => $token] = ApiToken::issue('org_seats-sdk', 'org_seats');
        $auth = ['Authorization' => 'Bearer '.$token];

        app(SubscribesOrganizations::class)->subscribe($organization, $plan, seats: 2);
        $this->assertSame(2_000, $this->walletBalance('org_seats'));

        // A quantity change rescales the per-seat allotment (forfeit-and-regrant reset).
        $this->postJson('/api/v1/subscriptions/org_seats/quantity', ['seats' => 5], $auth)->assertOk();
        $this->assertSame(5_000, $this->walletBalance('org_seats'));
    }

    public function test_aligned_addon_prorates_to_the_base_period(): void
    {
        $auth = $this->subscribed('org_addon_a', 'team');
        $before = $this->walletBalance('org_addon_a');

        $subscription = Subscription::query()->where('organization_id', 'org_addon_a')->firstOrFail();
        $basePeriodEnd = $subscription->current_period_end?->toDateString();

        $preview = $this->postJson('/api/v1/subscriptions/org_addon_a/addons', [
            'key' => 'priority-support',
            'price_minor' => 60_000,
            'currency' => 'DKK',
            'alignment' => 'aligned',
            'credit_allotment' => 10_000,
            'preview' => true,
        ], $auth);

        $preview->assertOk()->assertJsonPath('alignment', 'aligned');
        // Aligned add-on bills and grants over the BASE subscription period.
        $this->assertStringStartsWith((string) $basePeriodEnd, (string) $preview->json('period_end'));
        $this->assertGreaterThan(0, $preview->json('charge_minor'));
        $this->assertLessThan(60_000, $preview->json('charge_minor'));
        $grantedAllotment = $preview->json('allotment');
        $this->assertGreaterThan(0, $grantedAllotment);
        $this->assertLessThanOrEqual(10_000, $grantedAllotment);

        // Attaching it charges the same amount and lands the allotment in the wallet.
        $this->postJson('/api/v1/subscriptions/org_addon_a/addons', [
            'key' => 'priority-support',
            'price_minor' => 60_000,
            'currency' => 'DKK',
            'alignment' => 'aligned',
            'credit_allotment' => 10_000,
        ], $auth)->assertCreated()->assertJsonPath('add_on.key', 'priority-support');

        $this->assertSame($before + (int) $grantedAllotment, $this->walletBalance('org_addon_a'));

        // The add-on is surfaced on the subscription.
        $this->getJson('/api/v1/subscriptions/org_addon_a', $auth)
            ->assertOk()
            ->assertJsonPath('add_ons.0.key', 'priority-support')
            ->assertJsonPath('add_ons.0.alignment', 'aligned');

        // Detaching removes it.
        $this->deleteJson('/api/v1/subscriptions/org_addon_a/addons/priority-support', [], $auth)
            ->assertOk()
            ->assertJsonPath('add_ons', []);
    }

    public function test_independent_addon_bills_on_its_own_cycle(): void
    {
        $auth = $this->subscribed('org_addon_i', 'team');

        // An annual add-on on a monthly base: it runs on its OWN yearly cycle anchored on
        // Jan 1, so its period ends the following January, not the base month end.
        $preview = $this->postJson('/api/v1/subscriptions/org_addon_i/addons', [
            'key' => 'annual-audit',
            'price_minor' => 120_000,
            'currency' => 'DKK',
            'alignment' => 'independent',
            'interval' => 'yearly',
            'anchor_day' => 1,
            'anchor_month' => 1,
            'credit_allotment' => 1_200,
            'preview' => true,
        ], $auth);

        $preview->assertOk()->assertJsonPath('alignment', 'independent');
        // Independent cycle: the period ends on the next January anchor, distinct from the
        // base subscription's month end.
        $this->assertStringContainsString('-01-01', (string) $preview->json('period_end'));
        $this->assertGreaterThan(0, $preview->json('charge_minor'));
        $this->assertLessThan(120_000, $preview->json('charge_minor'));
    }

    public function test_scheduled_change_defers_while_immediate_applies_now(): void
    {
        // Immediate: the plan changes on the spot.
        $now = $this->subscribed('org_now', 'starter');
        $this->postJson('/api/v1/subscriptions/org_now/change', ['plan' => 'team', 'when' => 'now'], $now)
            ->assertOk()
            ->assertJsonPath('scheduled', false);
        $this->getJson('/api/v1/subscriptions/org_now', $now)->assertJsonPath('plan', 'team');

        // Deferred: the plan does NOT change now; the pending change is surfaced distinctly.
        $later = $this->subscribed('org_later', 'starter');
        $scheduled = $this->postJson('/api/v1/subscriptions/org_later/change', ['plan' => 'team', 'when' => 'period_end'], $later);
        $scheduled->assertOk()->assertJsonPath('scheduled', true);
        $this->assertNotNull($scheduled->json('effective_at'));

        $show = $this->getJson('/api/v1/subscriptions/org_later', $later);
        $show->assertJsonPath('plan', 'starter')
            ->assertJsonPath('pending_change.plan', 'team');

        // Once the effective instant passes, the scheduled pass enacts it.
        $this->travelTo(Carbon::parse('2026-09-01'));
        $applied = app(ManagesSubscriptionDepth::class)->applyDueScheduledChanges();
        $this->travelBack();

        $this->assertSame(1, $applied);
        $reread = Subscription::query()->where('organization_id', 'org_later')->firstOrFail();
        $this->assertSame('team', $reread->plan?->key);
        $this->assertNull($reread->pending_plan_id);
    }
}
