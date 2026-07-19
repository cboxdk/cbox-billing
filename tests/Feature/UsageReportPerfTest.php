<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\UsageReport;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Models\Organization;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-1: the Usage screen paginates organizations AT THE DATABASE and computes usage only for
 * the visible page — never the whole fleet. With ten orgs and a page size of eight, exactly
 * eight orgs are computed, and the total still reflects all ten.
 */
class UsageReportPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_only_the_visible_page_of_organizations_has_its_usage_computed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Organization::query()->create([
                'id' => sprintf('org_u%02d', $i),
                'name' => sprintf('Usage Org %02d', $i),
                'billing_country' => 'DK',
            ]);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $servingReads = static fn (array $q): int => count(array_filter($q, static fn (string $sql): bool => str_contains($sql, 'from "subscriptions"')));

        // Baseline: the serving-subscription reads to compute ONE org's card.
        app(SubscriptionMeterPolicyResolver::class)->flush();
        app(UsageReport::class)->forOrganization(Organization::query()->findOrFail('org_u00'));
        $perOrg = $servingReads($queries);
        $this->assertGreaterThan(0, $perOrg);

        // The full page.
        $queries = [];
        app(SubscriptionMeterPolicyResolver::class)->flush();
        $page = app(UsageReport::class)->paginate(null, 8);

        // The page holds eight computed cards; the total still reflects the whole fleet.
        $this->assertCount(8, $page->items());
        $this->assertSame(10, $page->total());

        // Usage was computed for the eight visible orgs ONLY — the per-org cost scaled by the
        // page size, not by the full ten-org fleet (which would be 10 × $perOrg).
        $this->assertSame(8 * $perOrg, $servingReads($queries));
    }
}
