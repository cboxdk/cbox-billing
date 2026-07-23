<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Nexus\NexusReporter;
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

        $this->invoice('USCO-1', 'ca-buyer', 60_000_000, 'open');   // $600k, counts
        $this->invoice('USCO-2', 'ca-buyer', 5_000_000, 'paid');    // $50k, counts
        $this->invoice('USCO-3', 'ca-buyer', 99_900_000, 'draft');  // excluded (draft)
        $this->invoice('USCO-4', 'ca-buyer', 40_000_000, 'open', 'DKK'); // excluded (currency)

        $activity = $this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-CA'));

        $this->assertNotNull($activity);
        $this->assertSame(650_000, $activity->salesDollars); // $600k + $50k
        $this->assertSame(2, $activity->transactions);

        // No US sales into Texas, and non-US is out of scope entirely.
        $this->assertNull($this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('US-TX')));
        $this->assertNull($this->app->make(SalesLedger::class)->activityFor(new SubdivisionCode('CA-QC')));
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
        $this->defaultUsSeller(); // registered US-NY only; no US buyers, no invoices

        // Operator declares physical presence in Washington — a trigger on its own.
        config(['billing.nexus.physical_presence' => ['US-WA']]);

        $threshold = new EconomicNexusThreshold(100_000, null, NexusCombinator::SalesOnly);
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-WA' => $threshold, 'US-NY' => $threshold,
        ]));
        // Re-resolve the config-backed bindings so they pick up the presence + threshold.
        $this->app->forgetInstance(PhysicalNexus::class);
        $this->app->forgetInstance(NexusEngine::class);
        $this->app->forgetInstance(NexusReporter::class);

        $report = $this->app->make(NexusReporter::class)->report();

        // WA is triggered by presence despite zero sales; NY is the held registration.
        $this->assertSame(['US-WA'], array_map(static fn ($e) => $e->state->value, $report->triggered()));
        $this->assertTrue($report->forState('US-WA')?->physicalPresence);
        $this->assertSame(['US-NY'], array_map(static fn ($e) => $e->state->value, $report->registered()));
    }
}
