<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Retirement\PlanRetirementService;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRetirementEvent;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionMrrMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two tables were created after the original livemode→environment sweep and never added to its
 * list, so `EnvironmentScope` could not reach them:
 *
 *  - `subscription_mrr_movements` — the MRR-movement waterfall read the log UNSCOPED while adding
 *    the steady book from a plane-SCOPED subscription query. A production console with no
 *    subscriptions at all reported expansion MRR produced entirely by a sandbox experiment. That
 *    is the number that goes in a board deck.
 *  - `plan_retirement_events` — `unresolved()` filtered by nothing, so a sandbox retirement
 *    surfaced in the PRODUCTION dashboard worklist against real customers.
 *
 * Both directions are asserted, and each test re-reads the row unscoped to prove the sandbox data
 * still EXISTS — isolation here is a scope, not a delete.
 */
class CrossPlaneRevenueAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    /**
     * Create the organization IN THE CURRENT PLANE, not unscoped. An earlier version of this
     * fixture created the org in production and then wrote the movement in the sandbox, which is a
     * state the app cannot actually produce — and, worse, it meant these tests would still pass if
     * the migration's plane derivation were wrong. Creating both in the same plane exercises the
     * real shape: the stamping trait derives the movement's plane from the ambient context, and the
     * org it points at lives there too.
     */
    private function org(string $id): void
    {
        Organization::query()->firstOrCreate(['id' => $id], [
            'name' => 'Org '.$id,
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);
    }

    public function test_a_sandbox_mrr_movement_is_invisible_to_the_production_waterfall(): void
    {
        $this->context()->setMode(BillingMode::Test);
        $this->org('org_plane');

        SubscriptionMrrMovement::query()->create([
            'organization_id' => 'org_plane',
            'currency' => 'DKK',
            'occurred_at' => now()->subDay(),
            'previous_mrr_minor' => 60_000,
            'new_mrr_minor' => 79_000,
            'kind' => SubscriptionMrrMovement::KIND_EXPANSION,
        ]);

        $this->assertSame(1, SubscriptionMrrMovement::query()->count(), 'The sandbox should see its own movement.');

        $this->context()->setMode(BillingMode::Live);

        $this->assertSame(
            0,
            SubscriptionMrrMovement::query()->count(),
            'A sandbox MRR movement leaked into the production waterfall — subscription_mrr_movements '
            .'is not plane-partitioned.',
        );

        // The row still exists; production simply cannot see it.
        $this->assertSame(1, SubscriptionMrrMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_a_production_mrr_movement_is_invisible_to_a_sandbox(): void
    {
        $this->org('org_plane');

        SubscriptionMrrMovement::query()->create([
            'organization_id' => 'org_plane',
            'currency' => 'DKK',
            'occurred_at' => now()->subDay(),
            'previous_mrr_minor' => 0,
            'new_mrr_minor' => 50_000,
            'kind' => SubscriptionMrrMovement::KIND_NEW,
        ]);

        $this->context()->setMode(BillingMode::Test);

        $this->assertSame(0, SubscriptionMrrMovement::query()->count());
        $this->assertSame(1, SubscriptionMrrMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_a_sandbox_retirement_event_stays_out_of_the_production_worklist(): void
    {
        $this->context()->setMode(BillingMode::Test);
        $this->org('org_plane');

        $product = Product::query()->create(['key' => 'retire-app', 'name' => 'Retire App']);
        $plan = Plan::query()->create([
            'product_id' => $product->id, 'key' => 'legacy', 'name' => 'Legacy',
            'interval' => 'month', 'active' => false,
        ]);
        $subscription = Subscription::query()->create([
            'organization_id' => 'org_plane', 'plan_id' => $plan->id, 'status' => 'active',
            'currency' => 'DKK', 'quantity' => 1,
            'current_period_start' => now()->subDays(5), 'current_period_end' => now()->addDays(25),
        ]);

        PlanRetirementEvent::query()->create([
            'subscription_id' => $subscription->id,
            'organization_id' => 'org_plane',
            'plan_id' => $plan->id,
            'retires_at' => now(),
            'type' => PlanRetirementEvent::TYPE_UNRESOLVED,
            'outcome' => 'unresolved',
        ]);

        $this->assertCount(1, app(PlanRetirementService::class)->unresolved());

        $this->context()->setMode(BillingMode::Live);

        $this->assertCount(
            0,
            app(PlanRetirementService::class)->unresolved(),
            'A sandbox plan-retirement event appeared in the production dashboard worklist — '
            .'plan_retirement_events is not plane-partitioned.',
        );

        $this->assertSame(1, PlanRetirementEvent::query()->withoutGlobalScopes()->count());
    }
}
