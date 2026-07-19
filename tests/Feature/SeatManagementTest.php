<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\Exceptions\SeatException;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\ApiToken;
use App\Models\CboxIdAccessGrant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\SeatAssignment;
use App\Models\Subscription;
use App\Models\SubscriptionMrrMovement;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The purchased + explicitly-assigned seat model, driven through the real management API and
 * the {@see ManagesSeats} service:
 *
 *  - PURCHASED Full seats (the subscription quantity) are the ONLY billing driver: buying
 *    raises the billed quantity through the engine's prorated changeQuantity (charge + MRR
 *    movement); releasing lowers it; releasing below the assigned count is refused.
 *  - ASSIGNMENT moves a member between Full (billed) and Light (free) without touching the
 *    billed quantity; assigning beyond the purchased cap or to a non-eligible subject is
 *    refused; Light members are not billed.
 */
class SeatManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** @return array{auth: array<string, string>, subscription: Subscription} */
    private function subscribe(string $org, int $seats = 2, string $plan = 'team'): array
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

        $subscription = Subscription::query()->where('organization_id', $org)->serving()->firstOrFail();

        return ['auth' => $auth, 'subscription' => $subscription];
    }

    private function mirror(string $org, string $subject, string $role = 'billing-operator'): void
    {
        CboxIdAccessGrant::query()->create(['organization_id' => $org, 'subject' => $subject, 'role' => $role]);
    }

    /**
     * A plain PER-SEAT plan (per_unit, 10 000 minor/seat DKK, no tiers) so a seat change
     * genuinely moves contributing MRR — the seeded `team` plan is graduated with a free
     * first tier, which the depth service still prorates a charge for but which reports zero
     * MRR at small seat counts.
     */
    private function perSeatPlan(): string
    {
        $product = Product::query()->firstOrFail();
        $plan = Plan::query()->create([
            'product_id' => $product->id,
            'key' => 'perseat',
            'name' => 'Per Seat',
            'interval' => 'month',
            'active' => true,
        ]);
        PlanPrice::query()->create([
            'plan_id' => $plan->id,
            'currency' => 'DKK',
            'price_minor' => 10_000,
            'pricing_model' => 'per_unit',
        ]);

        return 'perseat';
    }

    public function test_m2_a_first_assign_locks_the_subscription_before_counting_and_never_exceeds_the_cap(): void
    {
        $plan = $this->perSeatPlan();
        ['subscription' => $subscription] = $this->subscribe('org_race', seats: 1, plan: $plan);
        $this->mirror('org_race', 'sub_a');
        $this->mirror('org_race', 'sub_b');

        $seats = app(ManagesSeats::class);

        // The assign re-reads the SUBSCRIPTION row (under a lock) before the count+insert (M2):
        // a `FOR UPDATE COUNT` over the assignments locks no rows when the count is zero, so two
        // concurrent first-assigns for a single purchased seat could both read 0 and both
        // insert. Serializing on the subscription row is the fix — assert the lock read is issued.
        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $seats->assign($subscription, 'sub_a');

        $lockRead = array_filter($sql, static fn (string $q): bool => str_contains($q, 'from "subscriptions"') && str_contains($q, '"id" ='));
        $this->assertNotEmpty($lockRead, 'assign() must re-read the subscription row to serialize the free-seat check.');

        // The one purchased seat is taken; a second eligible subject is refused — the invariant
        // assigned ≤ purchased holds, exactly one assignment exists.
        try {
            $seats->assign($subscription, 'sub_b');
            $this->fail('Expected the second assign to be refused: no free seat.');
        } catch (SeatException) {
            // expected
        }

        $this->assertSame(1, SeatAssignment::query()->where('organization_id', 'org_race')->count());
    }

    public function test_buying_seats_raises_the_billed_quantity_with_a_prorated_charge_and_mrr_movement(): void
    {
        $plan = $this->perSeatPlan();
        ['subscription' => $subscription] = $this->subscribe('org_buy', seats: 2, plan: $plan);

        // Per-seat 10 000 minor/seat DKK: 2 → 4 seats is a 20 000 delta, prorated over the
        // days still to run — positive but below the full delta (preview == charge).
        $preview = app(ManagesSeats::class)->setPurchased($subscription, 4);
        $this->assertGreaterThan(0, $preview->charge->minor());
        $this->assertLessThan(20_000, $preview->charge->minor());

        // The billed quantity rose.
        $this->assertSame(4, Subscription::query()->where('organization_id', 'org_buy')->serving()->firstOrFail()->seats);

        // A seat expansion moved contributing MRR (2 → 4 seats priced by the engine):
        // 20 000 → 40 000. Purchased seats are the billing driver.
        $this->assertDatabaseHas('subscription_mrr_movements', [
            'organization_id' => 'org_buy',
            'kind' => SubscriptionMrrMovement::KIND_EXPANSION,
            'previous_mrr_minor' => 20_000,
            'new_mrr_minor' => 40_000,
        ]);
    }

    public function test_releasing_seats_lowers_the_billed_quantity(): void
    {
        $plan = $this->perSeatPlan();
        ['auth' => $auth, 'subscription' => $subscription] = $this->subscribe('org_release', seats: 4, plan: $plan);

        $this->postJson('/api/v1/subscriptions/org_release/seats', ['seats' => 2], $auth)
            ->assertOk()
            ->assertJsonPath('purchased', 2);

        $this->assertSame(2, $subscription->refresh()->seats);

        // A seat contraction moved contributing MRR down (4 → 2 seats): 40 000 → 20 000.
        $this->assertDatabaseHas('subscription_mrr_movements', [
            'organization_id' => 'org_release',
            'kind' => SubscriptionMrrMovement::KIND_CONTRACTION,
            'previous_mrr_minor' => 40_000,
            'new_mrr_minor' => 20_000,
        ]);
    }

    public function test_releasing_below_the_assigned_count_is_refused(): void
    {
        ['auth' => $auth, 'subscription' => $subscription] = $this->subscribe('org_guard', seats: 3);

        // Assign all three purchased seats to eligible members.
        foreach (['m1', 'm2', 'm3'] as $subject) {
            $this->mirror('org_guard', $subject);
            app(ManagesSeats::class)->assign($subscription, $subject);
        }

        // Releasing to 2 would strand an assigned member — refused (409), quantity unchanged.
        $this->postJson('/api/v1/subscriptions/org_guard/seats', ['seats' => 2], $auth)
            ->assertStatus(409);

        $this->assertSame(3, $subscription->refresh()->seats);
    }

    public function test_assign_moves_a_member_to_full_and_unassign_moves_it_back_to_light(): void
    {
        ['auth' => $auth] = $this->subscribe('org_assign', seats: 2);
        $this->mirror('org_assign', 'user_full', 'billing-admin');

        // Eligible-but-unassigned starts Light.
        $this->getJson('/api/v1/subscriptions/org_assign/seats', $auth)
            ->assertOk()
            ->assertJsonPath('purchased', 2)
            ->assertJsonPath('full_count', 0)
            ->assertJsonPath('light_count', 1);

        // Assigning moves it to Full (billed seat), the billed quantity unchanged.
        $this->postJson('/api/v1/subscriptions/org_assign/seats/assign', ['subject' => 'user_full'], $auth)
            ->assertOk()
            ->assertJsonPath('full_count', 1)
            ->assertJsonPath('light_count', 0)
            ->assertJsonPath('purchased', 2);
        $this->assertTrue(SeatAssignment::query()->where('organization_id', 'org_assign')->where('subject', 'user_full')->exists());

        // Unassigning moves it back to Light; purchased seats intact.
        $this->postJson('/api/v1/subscriptions/org_assign/seats/unassign', ['subject' => 'user_full'], $auth)
            ->assertOk()
            ->assertJsonPath('full_count', 0)
            ->assertJsonPath('light_count', 1)
            ->assertJsonPath('purchased', 2);
        $this->assertFalse(SeatAssignment::query()->where('organization_id', 'org_assign')->where('subject', 'user_full')->exists());
    }

    public function test_assigning_beyond_the_purchased_cap_is_refused(): void
    {
        ['auth' => $auth] = $this->subscribe('org_cap', seats: 1);
        $this->mirror('org_cap', 'first');
        $this->mirror('org_cap', 'second');

        // The one purchased seat goes to the first member.
        $this->postJson('/api/v1/subscriptions/org_cap/seats/assign', ['subject' => 'first'], $auth)->assertOk();

        // No free seat for the second — refused (409, "buy more seats").
        $this->postJson('/api/v1/subscriptions/org_cap/seats/assign', ['subject' => 'second'], $auth)
            ->assertStatus(409);

        $this->assertSame(1, SeatAssignment::query()->where('organization_id', 'org_cap')->count());
    }

    public function test_assigning_a_non_eligible_subject_is_refused(): void
    {
        ['auth' => $auth] = $this->subscribe('org_elig', seats: 2);

        // 'stranger' is not in the access mirror → not eligible → refused (409).
        $this->postJson('/api/v1/subscriptions/org_elig/seats/assign', ['subject' => 'stranger'], $auth)
            ->assertStatus(409);

        $this->assertSame(0, SeatAssignment::query()->where('organization_id', 'org_elig')->count());
    }

    public function test_light_members_are_counted_but_never_billed(): void
    {
        ['subscription' => $subscription] = $this->subscribe('org_light', seats: 2);

        $mrrBefore = SubscriptionRevenue::monthly($subscription)->minor();
        $movementsBefore = SubscriptionMrrMovement::query()->where('organization_id', 'org_light')->count();

        // Two eligible members join but are never assigned — pure Light.
        $this->mirror('org_light', 'light_a');
        $this->mirror('org_light', 'light_b');

        // MRR is driven by the PURCHASED seat count, not membership: unchanged, no movement.
        $this->assertSame($mrrBefore, SubscriptionRevenue::monthly($subscription->refresh())->minor());
        $this->assertSame($movementsBefore, SubscriptionMrrMovement::query()->where('organization_id', 'org_light')->count());

        $breakdown = app(ManagesSeats::class)->breakdown($subscription);
        $this->assertSame(2, $breakdown->purchased);
        $this->assertSame(0, $breakdown->fullCount());
        $this->assertSame(2, $breakdown->lightCount());
    }

    public function test_the_service_refuses_dropping_purchased_below_one(): void
    {
        ['subscription' => $subscription] = $this->subscribe('org_min', seats: 2);

        $this->expectException(SeatException::class);
        app(ManagesSeats::class)->setPurchased($subscription, 0);
    }
}
