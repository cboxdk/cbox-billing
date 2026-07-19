<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Fx\EcbRatesParser;
use App\Billing\Fx\FxConverter;
use App\Billing\Reporting\Consolidated\ConsolidatedRevenueReport;
use App\Billing\Reporting\RevenueMetrics;
use App\Models\FxRate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\SellerEntity;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consolidated multi-entity, multi-currency reporting with FX normalization. Exact minor-unit
 * vectors against rates stored in `fx_rates` (never fabricated), covering the ECB parser +
 * cross-rate derivation, the as-of/rounding policy, and the consolidated MRR read model with a
 * reporting-currency switch and an entity filter. The per-currency `RevenueMetrics` numbers are
 * asserted to be unchanged by all of this.
 */
class ConsolidatedRevenueReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── ECB parser + cross-rate derivation ──────────────────────────────────────────────────

    public function test_ecb_adapter_parses_xml_into_base_eur_rows_and_derives_a_cross_rate(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
            .'<Cube><Cube time="2026-07-17">'
            .'<Cube currency="USD" rate="1.0895"/><Cube currency="DKK" rate="7.4604"/><Cube currency="JPY" rate="158.34"/>'
            .'</Cube></Cube></gesmes:Envelope>';

        $rates = (new EcbRatesParser)->parse($xml);
        $this->assertCount(3, $rates);
        foreach ($rates as $rate) {
            $this->assertSame('EUR', $rate->base);
            $this->assertSame('2026-07-17', $rate->asOf->toDateString());
            FxRate::query()->create([
                'as_of_date' => $rate->asOf->toDateString(),
                'base' => $rate->base, 'quote' => $rate->quote, 'rate' => (string) $rate->rate, 'source' => $rate->origin->value,
            ]);
        }

        $converter = app(FxConverter::class);
        $asOf = CarbonImmutable::parse('2026-07-18');

        // DKK → USD is derived via the EUR pivot: (EUR→USD)/(EUR→DKK) = 1.0895 / 7.4604.
        // 1000.00 DKK × (1.0895/7.4604) = 146.0378 → 146.04 USD (14604 minor), half-up.
        $conversion = $converter->convert(Money::ofMinor(100_000, 'DKK'), 'USD', $asOf);
        $this->assertSame(14_604, $conversion->converted->minor());
        $this->assertSame('USD', $conversion->converted->currency());
        $this->assertTrue($conversion->rate->derived);
        $this->assertSame('2026-07-17', $conversion->rate->asOf->toDateString());

        // A pair neither source lists (no EUR→GBP leg) is honestly unavailable — never invented.
        $this->assertNull($converter->tryConvert(Money::ofMinor(1_000, 'GBP'), 'USD', $asOf));
    }

    public function test_fx_converter_uses_the_on_or_nearest_before_rate(): void
    {
        $this->storeRate('2026-07-10', 'EUR', 'DKK', '7.0000');
        $this->storeRate('2026-07-17', 'EUR', 'DKK', '7.4604');

        $converter = app(FxConverter::class);

        // As-of between the two rows uses the nearest-before (2026-07-10): 100.00 → 700.00.
        $mid = $converter->convert(Money::ofMinor(10_000, 'EUR'), 'DKK', CarbonImmutable::parse('2026-07-15'));
        $this->assertSame(70_000, $mid->converted->minor());
        $this->assertSame('2026-07-10', $mid->rate->asOf->toDateString());

        // As-of after both uses the latest (2026-07-17): 100.00 → 746.04.
        $late = $converter->convert(Money::ofMinor(10_000, 'EUR'), 'DKK', CarbonImmutable::parse('2026-07-20'));
        $this->assertSame(74_604, $late->converted->minor());
        $this->assertSame('2026-07-17', $late->rate->asOf->toDateString());

        // A date before any stored rate has none — unavailable, not fabricated.
        $this->assertNull($converter->tryConvert(Money::ofMinor(10_000, 'EUR'), 'DKK', CarbonImmutable::parse('2026-07-01')));
    }

    // ── Consolidated MRR: exact minor units, reporting-currency switch, entity filter ────────

    public function test_consolidated_mrr_sums_each_currency_converted_at_the_effective_rate(): void
    {
        $this->seedBook();

        $report = app(ConsolidatedRevenueReport::class);
        $mrr = $report->mrr('EUR', null, CarbonImmutable::parse('2026-07-18'));

        $this->assertSame('EUR', $mrr->reportingCurrency);
        $this->assertSame(3, $mrr->subscriptions);

        // Native per currency: DKK 1000.00, EUR 200.00, USD 300.00.
        // → EUR at the effective rates:
        //   DKK 1000.00 / 7.4604            = 134.04  (13404 minor, inverse)
        //   EUR  200.00                     = 200.00  (20000 minor, base)
        //   USD  300.00 / 1.0895            = 275.36  (27536 minor, inverse)
        //   consolidated                     = 60940 minor.
        $this->assertSame(60_940, $mrr->mrr->minor());
        $this->assertSame(60_940 * 12, $mrr->arr->minor());
        $this->assertTrue($mrr->complete());

        $lines = collect($mrr->byCurrency)->keyBy('currency');

        $dkk = $lines['DKK'];
        $this->assertSame(100_000, $dkk->native->minor());
        $this->assertSame(13_404, $dkk->converted?->minor());
        $this->assertTrue($dkk->rate?->derived); // inverse of EUR→DKK
        $this->assertSame('2026-07-17', $dkk->rate?->asOf->toDateString());

        $usd = $lines['USD'];
        $this->assertSame(30_000, $usd->native->minor());
        $this->assertSame(27_536, $usd->converted?->minor());

        $eur = $lines['EUR'];
        $this->assertSame(20_000, $eur->native->minor());
        $this->assertSame(20_000, $eur->converted?->minor()); // reporting == native → rate 1

        // The consolidated total equals the exact sum of the converted per-currency lines.
        $sumOfLines = collect($mrr->byCurrency)->sum(fn ($l) => $l->converted?->minor() ?? 0);
        $this->assertSame(60_940, $sumOfLines);
    }

    public function test_changing_the_reporting_currency_reconverts(): void
    {
        $this->seedBook();
        $report = app(ConsolidatedRevenueReport::class);

        // Reporting in DKK:
        //   DKK 1000.00                        = 100000 (base)
        //   EUR  200.00 × 7.4604               = 149208 (direct EUR→DKK)
        //   USD  300.00 × (7.4604/1.0895)      = 205426 (cross via EUR)
        //   consolidated                        = 454634 minor.
        $mrr = $report->mrr('DKK', null, CarbonImmutable::parse('2026-07-18'));
        $this->assertSame(454_634, $mrr->mrr->minor());

        $lines = collect($mrr->byCurrency)->keyBy('currency');
        $this->assertSame(100_000, $lines['DKK']->converted?->minor());
        $this->assertSame(149_208, $lines['EUR']->converted?->minor());
        $this->assertSame(205_426, $lines['USD']->converted?->minor());
    }

    public function test_entity_filter_restricts_to_that_entitys_subscriptions(): void
    {
        $this->seedBook();
        $report = app(ConsolidatedRevenueReport::class);

        // The EUR and USD subscriptions are invoiced by 'cbox-us'; the DKK one by 'cbox-dk'.
        $mrr = $report->mrr('EUR', 'cbox-us', CarbonImmutable::parse('2026-07-18'));

        $this->assertSame('cbox-us', $mrr->entityFilter);
        $this->assertSame(2, $mrr->subscriptions);
        // EUR 20000 + USD 27536 = 47536; the DKK sub is excluded.
        $this->assertSame(47_536, $mrr->mrr->minor());
        $currencies = collect($mrr->byCurrency)->pluck('currency')->all();
        $this->assertEqualsCanonicalizing(['EUR', 'USD'], $currencies);

        // The default-entity view sees only the DKK sub.
        $dk = $report->mrr('EUR', 'cbox-dk', CarbonImmutable::parse('2026-07-18'));
        $this->assertSame(1, $dk->subscriptions);
        $this->assertSame(13_404, $dk->mrr->minor());
    }

    public function test_a_currency_without_a_rate_is_reported_unavailable_not_fabricated(): void
    {
        $this->seedBook();
        // Drop the USD leg so USD → EUR cannot be resolved.
        FxRate::query()->where('quote', 'USD')->delete();

        $mrr = app(ConsolidatedRevenueReport::class)->mrr('EUR', null, CarbonImmutable::parse('2026-07-18'));

        $this->assertFalse($mrr->complete());
        $this->assertContains('USD', $mrr->unavailable);

        $usd = collect($mrr->byCurrency)->firstWhere('currency', 'USD');
        $this->assertNotNull($usd);
        $this->assertFalse($usd->available());
        $this->assertNull($usd->converted);

        // The consolidated total excludes the unavailable currency (13404 DKK + 20000 EUR), never
        // an invented USD conversion.
        $this->assertSame(33_404, $mrr->mrr->minor());
    }

    public function test_per_currency_revenue_metrics_are_unchanged_by_consolidation(): void
    {
        $this->seedBook();

        // The existing per-currency read model still reports each currency on its own, untouched.
        $revenue = app(RevenueMetrics::class)->revenue();
        $this->assertSame(100_000, $revenue->lineFor('DKK')?->mrr->minor());
        $this->assertSame(20_000, $revenue->lineFor('EUR')?->mrr->minor());
        $this->assertSame(30_000, $revenue->lineFor('USD')?->mrr->minor());
    }

    public function test_analytics_revenue_screen_renders_the_consolidated_overlay(): void
    {
        $this->seedBook();

        $this->withSession($this->session)->get('/analytics/revenue?reporting=EUR')
            ->assertOk()
            ->assertSee('Consolidated recurring revenue')
            ->assertSee('By selling entity');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────

    /**
     * A three-currency book: one DKK, one EUR, one USD subscription on a single plan priced in
     * all three, each attributed to a selling entity via its invoice, plus the ECB rates.
     */
    private function seedBook(): void
    {
        // Selling entities: the seeded default 'cbox-dk' plus a second 'cbox-us'.
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

        $this->subscribe('org_dk', 'DKK', $plan, 'cbox-dk', 1);
        $this->subscribe('org_eu', 'EUR', $plan, 'cbox-us', 2);
        $this->subscribe('org_us', 'USD', $plan, 'cbox-us', 3);

        $this->storeRate('2026-07-17', 'EUR', 'DKK', '7.4604');
        $this->storeRate('2026-07-17', 'EUR', 'USD', '1.0895');
    }

    private function subscribe(string $orgId, string $currency, Plan $plan, string $seller, int $invoiceNo): void
    {
        $org = Organization::query()->create([
            'id' => $orgId, 'name' => ucfirst($orgId), 'billing_currency' => $currency, 'billing_country' => 'DK',
        ]);

        $subscription = Subscription::query()->create([
            'organization_id' => $org->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'seats' => 1,
            'current_period_start' => CarbonImmutable::parse('2026-07-01'),
            'current_period_end' => CarbonImmutable::parse('2026-08-01'),
        ]);

        Invoice::query()->create([
            'organization_id' => $org->id, 'subscription_id' => $subscription->id, 'seller' => $seller,
            'number' => $seller.'-'.$invoiceNo, 'currency' => $currency,
            'subtotal_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'status' => 'open',
        ]);
    }

    private function storeRate(string $date, string $base, string $quote, string $rate): void
    {
        FxRate::query()->create([
            'as_of_date' => $date, 'base' => $base, 'quote' => $quote, 'rate' => $rate, 'source' => 'ecb',
        ]);
    }
}
