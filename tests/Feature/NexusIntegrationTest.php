<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Nexus\NexusReporter;
use App\Models\FxRate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SellerEntity;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusEngine;
use Cbox\Nexus\Contracts\NexusRegistrations;
use Cbox\Nexus\Contracts\NexusThresholdSource;
use Cbox\Nexus\Contracts\PhysicalNexus;
use Cbox\Nexus\Contracts\SalesLedger;
use Cbox\Nexus\Enums\NexusCombinator;
use Cbox\Nexus\Enums\NexusStatus;
use Cbox\Nexus\Testing\ArrayNexusThresholdSource;
use Cbox\Nexus\ValueObjects\EconomicNexusThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The app-side wiring of cboxdk/laravel-nexus: the SalesLedger aggregates the
 * default seller's finalized US invoices by the buyer org's place of supply, and
 * NexusRegistrations reflects the seller's held registrations — so the engine sees
 * a real economic picture.
 */
class NexusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function defaultUsSeller(): SellerEntity
    {
        $seller = SellerEntity::query()->create([
            'id' => 'us-co', 'legal_name' => 'US Co', 'registration_number' => 'US-0001',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'USCO', 'is_default' => true,
        ]);

        $seller->taxRegistrations()->create(['country' => 'US', 'number' => 'NY-PERMIT-1', 'subdivision' => 'US-NY']);

        return $seller;
    }

    private function orgIn(string $id, string $subdivision): Organization
    {
        return Organization::query()->create([
            'id' => $id, 'name' => $subdivision.' Buyer', 'billing_email' => $id.'@example.test',
            'billing_country' => 'US', 'billing_subdivision' => $subdivision, 'billing_currency' => 'USD',
            'tax_id_validated' => false,
        ]);
    }

    /** A paid invoice issued at a specific instant — the two-calendar-year window needs dating. */
    private function invoiceIssuedAt(string $number, string $orgId, int $totalMinor, Carbon $issuedAt): void
    {
        Invoice::query()->create([
            'organization_id' => $orgId, 'seller' => 'us-co', 'number' => $number, 'currency' => 'USD',
            'total_minor' => $totalMinor, 'status' => 'paid', 'issued_at' => $issuedAt,
        ]);
    }

    private function invoice(string $number, string $orgId, int $totalMinor, string $status, string $currency = 'USD'): void
    {
        Invoice::query()->create([
            'organization_id' => $orgId, 'seller' => 'us-co', 'number' => $number, 'currency' => $currency,
            'total_minor' => $totalMinor, 'status' => $status, 'issued_at' => Carbon::now(),
        ]);
    }

    public function test_sales_ledger_aggregates_finalized_us_sales_by_buyer_state(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('ca-buyer', 'US-CA');

        // A foreign-currency sale still counts toward the threshold — valued in USD.
        FxRate::query()->create([
            'as_of_date' => Carbon::now()->subMonth(), 'base' => 'DKK', 'quote' => 'USD', 'rate' => '0.15', 'source' => 'override',
        ]);

        $this->invoice('USCO-1', 'ca-buyer', 60_000_000, 'open');   // $600k, counts
        $this->invoice('USCO-2', 'ca-buyer', 5_000_000, 'paid');    // $50k, counts
        $this->invoice('USCO-3', 'ca-buyer', 99_900_000, 'draft');  // excluded (draft)
        $this->invoice('USCO-4', 'ca-buyer', 40_000_000, 'open', 'DKK'); // 400,000 DKK → $60k @ 0.15

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CA'));

        $this->assertNotNull($activity);
        $this->assertSame(710_000, $activity->salesDollars); // $600k + $50k + $60k (DKK→USD)
        $this->assertSame(3, $activity->transactions);       // the DKK sale is a transaction too

        // No US sales into Texas, and non-US is out of scope entirely.
        $this->assertNull($this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-TX')));
        $this->assertNull($this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('CA-QC')));
    }

    public function test_foreign_sale_without_an_fx_rate_counts_the_transaction_but_not_the_dollars(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('ca-buyer', 'US-CA');

        $this->invoice('USCO-1', 'ca-buyer', 10_000_000, 'paid');        // $100k USD
        $this->invoice('USCO-2', 'ca-buyer', 40_000_000, 'open', 'DKK'); // no DKK→USD rate seeded

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CA'));

        $this->assertNotNull($activity);
        $this->assertSame(100_000, $activity->salesDollars); // unvaluable DKK dollars omitted, never fabricated
        $this->assertSame(2, $activity->transactions);       // but the DKK sale is still a transaction
    }

    public function test_physical_presence_is_active_on_its_final_day_and_not_after(): void
    {
        $seller = $this->defaultUsSeller();

        // A presence whose window ENDS today must still be active today (inclusive end),
        // not dropped a day early; one that ended yesterday must be gone.
        $seller->physicalPresence()->create(['subdivision' => 'US-WA', 'effective_to' => Carbon::now()]);
        $seller->physicalPresence()->create(['subdivision' => 'US-OR', 'effective_to' => Carbon::now()->subDay()]);

        $presence = $this->app->make(PhysicalNexus::class);

        $this->assertTrue($presence->hasPresenceIn(new SubdivisionCode('US-WA')));
        $this->assertFalse($presence->hasPresenceIn(new SubdivisionCode('US-OR')));
    }

    public function test_sales_ledger_adds_external_channel_sales_to_platform_sales(): void
    {
        $seller = $this->defaultUsSeller();
        $this->orgIn('tx-buyer', 'US-TX');
        $this->invoice('USCO-1', 'tx-buyer', 10_000_000, 'paid'); // $100k on this platform into TX

        // The seller also sold into TX (and into WA, where we have NO platform sales)
        // through a marketplace — those count toward each state's threshold too.
        $seller->externalSales()->create([
            'subdivision' => 'US-TX', 'period_year' => Carbon::now()->year,
            'sales_dollars' => 250_000, 'transactions' => 40, 'source' => 'Amazon Marketplace',
        ]);
        $seller->externalSales()->create([
            'subdivision' => 'US-WA', 'period_year' => Carbon::now()->year,
            'sales_dollars' => 120_000, 'transactions' => 15, 'source' => 'Amazon Marketplace',
        ]);

        $ledger = $this->app->make(SalesLedger::class);

        $tx = $ledger->activityFor(new SubdivisionCode('US-TX'));
        $this->assertNotNull($tx);
        $this->assertSame(350_000, $tx->salesDollars); // $100k platform + $250k external
        $this->assertSame(41, $tx->transactions);       // 1 + 40

        // WA has zero platform sales but real external sales — it must still surface.
        $wa = $ledger->activityFor(new SubdivisionCode('US-WA'));
        $this->assertNotNull($wa);
        $this->assertSame(120_000, $wa->salesDollars);
        $this->assertSame(15, $wa->transactions);
    }

    public function test_registrations_reflect_the_sellers_held_permits(): void
    {
        $this->defaultUsSeller();

        $registrations = $this->app->make(NexusRegistrations::class);

        $this->assertTrue($registrations->isRegisteredIn(new SubdivisionCode('US-NY')));
        $this->assertFalse($registrations->isRegisteredIn(new SubdivisionCode('US-CA')));
    }

    public function test_engine_triggers_on_crossing_and_reports_registered(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('ca-buyer', 'US-CA');
        $this->invoice('USCO-1', 'ca-buyer', 60_000_000, 'open'); // $600k into CA

        // Isolate from the live dataset: fake the thresholds so the test asserts the
        // app's ledger/registration wiring, not the network-backed source.
        $threshold = new EconomicNexusThreshold(500_000, null, NexusCombinator::SalesOnly);
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-CA' => $threshold, 'US-NY' => $threshold, 'US-TX' => $threshold,
        ]));
        $this->app->forgetInstance(NexusEngine::class);

        $engine = $this->app->make(NexusEngine::class);

        $this->assertSame(NexusStatus::Triggered, $engine->evaluate(new SubdivisionCode('US-CA'))->status);   // $600k >= $500k
        $this->assertSame(NexusStatus::Registered, $engine->evaluate(new SubdivisionCode('US-NY'))->status);  // held permit
        $this->assertSame(NexusStatus::Below, $engine->evaluate(new SubdivisionCode('US-TX'))->status);       // no activity
    }

    public function test_reporter_covers_buyer_states_and_registrations_for_the_default_seller(): void
    {
        $this->defaultUsSeller(); // registered in US-NY
        $this->orgIn('ca-buyer', 'US-CA');
        $this->invoice('USCO-1', 'ca-buyer', 60_000_000, 'open'); // $600k into CA

        $threshold = new EconomicNexusThreshold(500_000, null, NexusCombinator::SalesOnly);
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-CA' => $threshold, 'US-NY' => $threshold,
        ]));
        $this->app->forgetInstance(NexusEngine::class);

        $report = $this->app->make(NexusReporter::class)->report();

        // Relevant states = US-CA (a buyer's place of supply) + US-NY (a registration).
        $this->assertSame(['US-CA'], array_map(static fn ($e) => $e->state->value, $report->triggered()));
        $this->assertSame(['US-NY'], array_map(static fn ($e) => $e->state->value, $report->registered()));
        $this->assertSame([], $report->approaching());
    }

    public function test_declared_physical_presence_surfaces_as_triggered_without_sales(): void
    {
        $seller = $this->defaultUsSeller(); // registered US-NY only; no US buyers, no invoices

        // Operator declares physical presence in Washington — a trigger on its own.
        $seller->physicalPresence()->create(['subdivision' => 'US-WA']);

        $threshold = new EconomicNexusThreshold(100_000, null, NexusCombinator::SalesOnly);
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-WA' => $threshold, 'US-NY' => $threshold,
        ]));
        // Re-resolve the engine + reporter so they pick up the faked threshold source.
        $this->app->forgetInstance(NexusEngine::class);
        $this->app->forgetInstance(NexusReporter::class);

        $report = $this->app->make(NexusReporter::class)->report();

        // WA is triggered by presence despite zero sales; NY is the held registration.
        $this->assertSame(['US-WA'], array_map(static fn ($e) => $e->state->value, $report->triggered()));
        $this->assertTrue($report->forState('US-WA')?->physicalPresence);
        $this->assertSame(['US-NY'], array_map(static fn ($e) => $e->state->value, $report->registered()));
    }

    /**
     * "Previous OR current calendar year" tests each year SEPARATELY. Summing them — which a
     * single window starting at last January does — roughly doubles the measured figure, so a
     * seller steadily under a threshold reads as over it and registers, files and collects tax in
     * a state where it has no obligation.
     */
    public function test_the_two_calendar_years_are_evaluated_separately_not_summed(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('org_ca_split', 'US-CA');

        // $60k in each of the two years. Summed that is $120k (over California's $100k threshold);
        // evaluated correctly it is $60k, which is under.
        $this->invoiceIssuedAt('CA-SPLIT-1', 'org_ca_split', 6_000_000, Carbon::now());
        $this->invoiceIssuedAt('CA-SPLIT-2', 'org_ca_split', 6_000_000, Carbon::now()->subYear());

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CA'));

        $this->assertNotNull($activity);
        $this->assertSame(
            60_000,
            $activity->salesDollars,
            'Each calendar year must be measured on its own and the larger reported — summing them '
            .'would report $120,000 and trigger a registration obligation that does not exist.',
        );
    }

    /** The larger of the two years wins, whichever one it is. */
    public function test_the_larger_year_is_reported(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('org_ca_larger', 'US-CA');

        $this->invoiceIssuedAt('CA-BIG-1', 'org_ca_larger', 2_000_000, Carbon::now());
        $this->invoiceIssuedAt('CA-BIG-2', 'org_ca_larger', 9_000_000, Carbon::now()->subYear());

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CA'));

        $this->assertNotNull($activity);
        $this->assertSame(90_000, $activity->salesDollars);
    }

    public function test_an_and_state_is_not_triggered_by_mixing_metrics_across_two_years(): void
    {
        $this->defaultUsSeller();
        $this->orgIn('ct-buyer', 'US-CT');

        // Connecticut is an AND state: $100k AND 200 transactions. Neither year crosses BOTH.
        //   previous year: $600k over 40 transactions  — dollars yes, transactions no
        //   current year:  $80k  over 250 transactions — transactions yes, dollars no
        $previous = Carbon::now()->subYear()->startOfYear()->addMonth();
        $current = Carbon::now()->startOfYear()->addMonth();

        for ($i = 0; $i < 40; $i++) {
            $this->invoiceIssuedAt('PREV-'.$i, 'ct-buyer', 1_500_000, $previous); // 40 × $15k = $600k
        }

        for ($i = 0; $i < 250; $i++) {
            $this->invoiceIssuedAt('CURR-'.$i, 'ct-buyer', 32_000, $current); // 250 × $320 = $80k
        }

        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-CT' => new EconomicNexusThreshold(100_000, 200, NexusCombinator::SalesAndTransactions),
        ]));
        $this->app->forgetInstance(NexusEngine::class);
        $this->app->forgetInstance(SalesLedger::class);

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CT'));

        // The reported pair must be ONE real year, never $600k paired with 250 transactions.
        $this->assertNotNull($activity);
        $this->assertTrue(
            ($activity->salesDollars === 600_000 && $activity->transactions === 40)
            || ($activity->salesDollars === 80_000 && $activity->transactions === 250),
            sprintf(
                'Expected one year\'s real pair, got $%d over %d transactions — a year that never happened.',
                $activity->salesDollars,
                $activity->transactions,
            ),
        );

        // And therefore the seller is NOT told to register somewhere it has no obligation.
        // `Approaching` is the correct answer here and is what this asserts against: one leg is
        // well over, so the state is worth watching — but neither year crossed BOTH legs, so it
        // must not be `Triggered`. Before the fix it was, on a pair that never existed.
        $status = $this->app->make(NexusEngine::class)->evaluate(new SubdivisionCode('US-CT'))->status;

        $this->assertNotSame(NexusStatus::Triggered, $status);
        $this->assertSame(NexusStatus::Approaching, $status);
    }
}
