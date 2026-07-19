<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Metering\EntitlementsView;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use App\Models\Plan;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-2: resolving an org's per-meter policy map must read the serving subscription, the
 * plan's entitlement rows and the meter catalog ONCE per org (memoized for the request), not
 * three queries per meter. The catalog seeds four meters, so the naive path re-queried the
 * subscription four times; the fixed path reads it once.
 */
class EntitlementsResolverPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_resolving_all_meters_reads_the_serving_subscription_only_once(): void
    {
        $org = Organization::query()->create(['id' => 'org_perf2', 'name' => 'Perf2', 'billing_country' => 'DK']);
        $plan = Plan::query()->with(['prices', 'product'])->where('key', 'team')->firstOrFail();
        app(SubscribesOrganizations::class)->subscribe($org, $plan, seats: 5);

        // A fresh memo so the count reflects exactly this resolution pass.
        app(SubscriptionMeterPolicyResolver::class)->flush();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $policies = app(EntitlementsView::class)->forOrganization('org_perf2');

        // The four seeded meters are all resolved.
        $this->assertCount(4, $policies);

        $servingReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "subscriptions"')));
        $entitlementReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "plan_entitlements"')));

        // Once each per org — memoized — not once per meter (which would be four).
        $this->assertSame(1, $servingReads, 'The serving subscription must be read once, not per meter.');
        $this->assertSame(1, $entitlementReads, 'The plan entitlements must be read once, not per meter.');
    }

    public function test_a_subscription_write_invalidates_the_memo(): void
    {
        $org = Organization::query()->create(['id' => 'org_perf2b', 'name' => 'Perf2b', 'billing_country' => 'DK']);
        $plan = Plan::query()->with(['prices', 'product'])->where('key', 'team')->firstOrFail();
        $subscription = app(SubscribesOrganizations::class)->subscribe($org, $plan, seats: 5);

        // Warm the memo: at least one meter resolves to an enabled policy.
        $before = app(EntitlementsView::class)->forOrganization('org_perf2b');
        $this->assertTrue(collect($before)->contains(static fn (array $p): bool => $p['enabled'] === true));

        // Pausing the subscription (a write) invalidates the memo, so metering is now suspended —
        // every meter resolves deny-by-default rather than replaying the stale enabled policy.
        $subscription->forceFill(['paused_at' => now()])->save();

        $after = app(EntitlementsView::class)->forOrganization('org_perf2b');
        $this->assertTrue(collect($after)->every(static fn (array $p): bool => $p['enabled'] === false));
    }
}
