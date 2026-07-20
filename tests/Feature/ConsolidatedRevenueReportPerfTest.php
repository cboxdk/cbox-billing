<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\Consolidated\ConsolidatedRevenueReport;
use App\Models\FxRate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\SellerEntity;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-1: the consolidated multi-entity/currency MRR read model must NOT hydrate the whole
 * subscriptions + invoices tables per render. It folds the book into a cached native aggregate
 * (busted on any billing write) and applies FX fresh, so:
 *
 *  - the query count for a render is constant regardless of how many subscriptions/invoices
 *    exist (no N+1 on plan/price/coupon, no per-invoice hydrate for entity resolution), and
 *  - a warm cache renders with ZERO subscription/invoice reads, while
 *  - the numbers are byte-for-byte identical to a cold, uncached computation.
 */
class ConsolidatedRevenueReportPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_render_query_count_is_constant_regardless_of_book_size(): void
    {
        CarbonImmutable::setTestNow('2026-07-18');
        $plan = $this->seedCommon();

        // The distinct currency + selling-entity SET is what legitimately drives FX/entity
        // queries; hold it fixed (DKK/EUR/USD × cbox-dk/cbox-us) across both books so the only
        // variable is the subscription/invoice COUNT — which must NOT add queries (no N+1).
        $currencies = ['DKK', 'EUR', 'USD'];
        $sellers = ['cbox-dk', 'cbox-us'];

        // A small book: one subscription per (currency × seller) combination.
        $n = 0;
        foreach ($currencies as $currency) {
            foreach ($sellers as $seller) {
                $this->subscribe('org_a'.$n, $currency, $plan, $seller, 1, invoices: 1);
                $n++;
            }
        }

        // Warm the ONE-TIME schema-introspection the environment (plane) scope performs the first
        // time it touches each config table — a memoised column-presence guard that emits a couple
        // of PRAGMA/sqlite_master probes on first use, then never again this process. It is a
        // process-level cost, not a per-render or book-size cost, so warming it here keeps the
        // measurement about the only thing under test: book-size-driven queries (no N+1).
        app(ConsolidatedRevenueReport::class)->mrr('DKK');

        $small = $this->countRenderQueries();

        // A much larger book: the SAME currency/seller set, many more subscriptions, and several
        // invoices each (to stress the entity resolver's grouped join too).
        for ($i = 0; $i < 30; $i++) {
            $currency = $currencies[$i % 3];
            $seller = $sellers[$i % 2];
            $this->subscribe('org_b'.$i, $currency, $plan, $seller, 100 + $i, invoices: 4);
            $n++;
        }

        $large = $this->countRenderQueries();

        // Constant — the fold is eager-loaded and the entity map is one grouped query, so a
        // 6x larger book with 4x the invoices issues the same number of queries.
        $this->assertSame(
            $small,
            $large,
            "Render query count must not grow with book size (small={$small}, large={$large}).",
        );
    }

    public function test_a_warm_cache_renders_with_zero_subscription_or_invoice_reads(): void
    {
        CarbonImmutable::setTestNow('2026-07-18');
        $plan = $this->seedCommon();
        $this->subscribe('org_c1', 'DKK', $plan, 'cbox-dk', 1);
        $this->subscribe('org_c2', 'USD', $plan, 'cbox-us', 2);

        // Cold render warms the aggregate cache.
        app(ConsolidatedRevenueReport::class)->mrr('DKK');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ConsolidatedRevenueReport::class)->mrr('DKK');

        $bookReads = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "subscriptions"') || str_contains($sql, 'from "invoices"'),
        );

        $this->assertCount(0, $bookReads, 'A warm aggregate cache must not re-read the subscriptions/invoices tables.');
    }

    public function test_cached_numbers_equal_a_freshly_computed_render(): void
    {
        CarbonImmutable::setTestNow('2026-07-18');
        $plan = $this->seedCommon();
        $this->subscribe('org_d1', 'DKK', $plan, 'cbox-dk', 1);
        $this->subscribe('org_d2', 'EUR', $plan, 'cbox-us', 2);
        $this->subscribe('org_d3', 'USD', $plan, 'cbox-us', 3);

        // Cold (fresh) computation.
        $fresh = app(ConsolidatedRevenueReport::class)->mrr('DKK');

        // Warm (cached) computation — same key, served from cache.
        $cached = app(ConsolidatedRevenueReport::class)->mrr('DKK');

        $this->assertSame($fresh->mrr->minor(), $cached->mrr->minor());
        $this->assertSame($fresh->arr->minor(), $cached->arr->minor());
        $this->assertSame($fresh->subscriptions, $cached->subscriptions);
        $this->assertSame(count($fresh->byCurrency), count($cached->byCurrency));
        $this->assertSame(count($fresh->byEntity), count($cached->byEntity));
    }

    public function test_a_billing_write_busts_the_cached_aggregate(): void
    {
        CarbonImmutable::setTestNow('2026-07-18');
        $plan = $this->seedCommon();
        $this->subscribe('org_e1', 'DKK', $plan, 'cbox-dk', 1);

        $before = app(ConsolidatedRevenueReport::class)->mrr('DKK');
        $this->assertSame(1, $before->subscriptions);

        // A new subscription is a write — the saved hook bumps the aggregate epoch.
        $this->subscribe('org_e2', 'DKK', $plan, 'cbox-dk', 2);

        $after = app(ConsolidatedRevenueReport::class)->mrr('DKK');
        $this->assertSame(2, $after->subscriptions, 'A new subscription must invalidate the cached aggregate.');
    }

    private function countRenderQueries(): int
    {
        // Bust the cache so each measured render is a cold fold.
        app(ConsolidatedRevenueReport::class)->flush();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ConsolidatedRevenueReport::class)->mrr('DKK');

        // DB::listen has no unregister; count only this render's queries by draining after.
        $count = count($queries);
        $queries = [];

        return $count;
    }

    private function seedCommon(): Plan
    {
        $this->seed(SellerEntitySeeder::class);
        SellerEntity::query()->create([
            'id' => 'cbox-us', 'legal_name' => 'Cbox US Inc', 'registration_number' => 'US-000',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'CBOX-US', 'is_default' => false,
        ]);

        $product = Product::query()->create(['key' => 'consolidated', 'name' => 'Consolidated']);
        $plan = Plan::query()->create([
            'product_id' => $product->id, 'key' => 'global', 'name' => 'Global', 'interval' => 'month', 'active' => true,
        ]);
        foreach (['DKK' => 100_000, 'EUR' => 20_000, 'USD' => 30_000] as $currency => $minor) {
            PlanPrice::query()->create([
                'plan_id' => $plan->id, 'currency' => $currency, 'price_minor' => $minor, 'pricing_model' => 'flat',
            ]);
        }
        $plan->load('prices.tiers');

        FxRate::query()->create(['as_of_date' => '2026-07-17', 'base' => 'EUR', 'quote' => 'DKK', 'rate' => '7.4604', 'source' => 'ecb']);
        FxRate::query()->create(['as_of_date' => '2026-07-17', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.0895', 'source' => 'ecb']);

        return $plan;
    }

    private function subscribe(string $orgId, string $currency, Plan $plan, string $seller, int $invoiceNo, int $invoices = 1): void
    {
        $org = Organization::query()->create([
            'id' => $orgId, 'name' => ucfirst($orgId), 'billing_currency' => $currency, 'billing_country' => 'DK',
        ]);

        $subscription = Subscription::query()->create([
            'organization_id' => $org->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'seats' => 1,
            'current_period_start' => CarbonImmutable::parse('2026-07-01'),
            'current_period_end' => CarbonImmutable::parse('2026-08-01'),
        ]);

        for ($n = 0; $n < $invoices; $n++) {
            Invoice::query()->create([
                'organization_id' => $org->id, 'subscription_id' => $subscription->id, 'seller' => $seller,
                'number' => $seller.'-'.$orgId.'-'.$invoiceNo.'-'.$n, 'currency' => $currency,
                'subtotal_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'status' => 'open',
            ]);
        }
    }
}
