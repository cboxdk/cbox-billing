<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Features\FeatureEntitlements;
use App\Models\Feature;
use App\Models\OrganizationFeatureOverride;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-4: resolving an org's boolean/config feature entitlements must read the serving
 * subscription, the plan's grant rows, the org's overrides and the feature catalog ONCE per
 * org (memoized for the request) — never once per feature. And across requests the same context
 * must be served from the short-TTL cache without re-querying, invalidated the moment a
 * grant/override/subscription write bumps the epoch.
 */
class FeatureEntitlementsResolverPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_resolving_all_features_reads_each_table_once(): void
    {
        // A fresh memo + epoch so the count reflects exactly this resolution pass.
        app(FeatureEntitlements::class)->flush();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        // Hverdag (Team) resolves the whole catalog of features.
        $resolved = app(FeatureEntitlements::class)->forOrganization('org_hverdag');

        $this->assertNotEmpty($resolved);

        $reads = static fn (string $table): int => count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'),
        ));

        // Once each — memoized — not once per feature.
        $this->assertSame(1, $reads('subscriptions'), 'The serving subscription must be read once.');
        $this->assertSame(1, $reads('features'), 'The feature catalog must be read once.');
        $this->assertSame(1, $reads('plan_features'), 'The plan grants must be read once.');
        $this->assertSame(1, $reads('organization_feature_overrides'), 'The org overrides must be read once.');
    }

    public function test_a_second_request_is_served_from_the_cross_request_cache(): void
    {
        // Warm the cache in one "request".
        app(FeatureEntitlements::class)->forOrganization('org_hverdag');

        // A fresh instance (new request) keeps its own empty memo but shares the cache store.
        app()->forgetInstance(FeatureEntitlements::class);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $resolved = app(FeatureEntitlements::class)->forOrganization('org_hverdag');

        $this->assertNotEmpty($resolved);
        $this->assertCount(0, $queries, 'A warm cross-request cache must resolve with zero queries.');
    }

    public function test_an_override_write_busts_the_cross_request_cache(): void
    {
        // Warm the cache: Hverdag (Team) does not grant platform.multi_tenant.
        $before = app(FeatureEntitlements::class)->forOrganization('org_hverdag');
        $this->assertFalse($before['platform.multi_tenant']->enabled);

        // A write bumps the epoch (via the saved-event flush hook), so the cache key rotates.
        $feature = Feature::query()->where('key', 'platform.multi_tenant')->firstOrFail();
        OrganizationFeatureOverride::query()->create([
            'organization_id' => 'org_hverdag',
            'feature_id' => $feature->id,
            'granted' => true,
            'value' => null,
            'reason' => 'perf-test',
        ]);

        // A fresh instance (new request) must observe the write, not the stale cached context.
        app()->forgetInstance(FeatureEntitlements::class);
        $after = app(FeatureEntitlements::class)->forOrganization('org_hverdag');

        $this->assertTrue($after['platform.multi_tenant']->enabled);
        $this->assertSame('override', $after['platform.multi_tenant']->source->value);
    }
}
