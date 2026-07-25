<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\RevenueMetrics;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The console landing page's revenue tiles must cost a FLAT number of queries regardless of how
 * many subscriptions are being billed.
 *
 * This guards a real regression: `subscriptionMrrs()` used `cursor()`, and Eloquent's `cursor()`
 * streams off the base query builder without ever calling `eagerLoadRelations()` — so the `with()`
 * was silently discarded and every row lazy-loaded its organization, plan, prices, tiers and
 * coupon. Measured before the fix: 369 queries at 50 subscriptions, 1,261 at 200. The bug is
 * invisible in a test that only checks the returned numbers, which is why this asserts the query
 * COUNT and compares two different data volumes rather than asserting an absolute ceiling.
 */
class RevenueMetricsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedSubscriptions(int $count, string $prefix): void
    {
        $product = Product::query()->firstOrCreate(['key' => 'budget-app'], ['name' => 'Budget App']);

        $plan = Plan::query()->firstOrCreate(
            ['key' => 'budget-plan'],
            ['product_id' => $product->id, 'name' => 'Budget Plan', 'interval' => 'month', 'active' => true],
        );

        PlanPrice::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'currency' => 'DKK'],
            ['price_minor' => 10_000, 'pricing_model' => 'flat'],
        );

        for ($i = 0; $i < $count; $i++) {
            $org = Organization::query()->create([
                'id' => $prefix.'_org_'.$i,
                'name' => 'Org '.$i,
                'billing_email' => $prefix.$i.'@example.test',
                'billing_country' => 'DK',
            ]);

            Subscription::query()->create([
                'organization_id' => $org->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'currency' => 'DKK',
                'quantity' => 1,
                'current_period_start' => now()->subDays(5),
                'current_period_end' => now()->addDays(25),
            ]);
        }
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_revenue_tiles_do_not_scale_their_query_count_with_subscription_count(): void
    {
        $metrics = app(RevenueMetrics::class);

        $this->seedSubscriptions(5, 'small');
        $atFive = $this->queriesFor(static function () use ($metrics): void {
            $metrics->revenue();
            $metrics->planBreakdown();
            $metrics->outstanding();
        });

        $this->seedSubscriptions(25, 'large');
        $atThirty = $this->queriesFor(static function () use ($metrics): void {
            $metrics->revenue();
            $metrics->planBreakdown();
            $metrics->outstanding();
        });

        // 6× the subscriptions must not mean meaningfully more queries. A small allowance covers
        // chunk boundaries; per-row lazy loading would blow straight past it (at the old
        // ~6 queries/row this delta was ~150).
        $this->assertLessThanOrEqual(
            $atFive + 2,
            $atThirty,
            "Query count grew from {$atFive} to {$atThirty} as subscriptions went 5 → 30 — "
            .'the revenue read model is lazy-loading per row again (check that it uses '
            .'lazyById() rather than cursor(), which discards eager loads).',
        );
    }

    /** Summing open invoices must be one aggregate query, not one model per invoice. */
    public function test_outstanding_sums_in_sql(): void
    {
        $metrics = app(RevenueMetrics::class);

        $queries = $this->queriesFor(static function () use ($metrics): void {
            $metrics->outstanding();
        });

        // One query resolves the primary currency, one sums. Hydrating invoices would not change
        // the COUNT, so this documents the contract; the memory win is the point.
        $this->assertLessThanOrEqual(3, $queries);
    }
}
