<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Nexus\NexusAlertEmitter;
use App\Mail\NexusAlertMail;
use App\Models\Invoice;
use App\Models\NexusAlertDispatch;
use App\Models\Organization;
use App\Models\SellerEntity;
use Cbox\Nexus\Contracts\NexusEngine;
use Cbox\Nexus\Contracts\NexusThresholdSource;
use Cbox\Nexus\Enums\NexusCombinator;
use Cbox\Nexus\Testing\ArrayNexusThresholdSource;
use Cbox\Nexus\ValueObjects\EconomicNexusThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The economic-nexus alert sweep: it records each state newly crossed into Triggered/Approaching
 * to the idempotency ledger exactly once per measurement period, and emails the configured
 * operations recipients (and only them) — with none configured the crossing is still recorded.
 */
class NexusAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function triggeredScenario(): void
    {
        $seller = SellerEntity::query()->create([
            'id' => 'us-co', 'legal_name' => 'US Co', 'registration_number' => 'US-0001',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'USCO', 'is_default' => true,
        ]);
        $seller->taxRegistrations()->create(['country' => 'US', 'number' => 'NY-PERMIT-1', 'subdivision' => 'US-NY']);

        Organization::query()->create([
            'id' => 'ca-buyer', 'name' => 'CA Buyer', 'billing_email' => 'ca@example.test',
            'billing_country' => 'US', 'billing_subdivision' => 'US-CA', 'billing_currency' => 'USD',
            'tax_id_validated' => false,
        ]);

        Invoice::query()->create([
            'organization_id' => 'ca-buyer', 'seller' => 'us-co', 'number' => 'USCO-1', 'currency' => 'USD',
            'total_minor' => 60_000_000, 'status' => 'open', 'issued_at' => Carbon::now(), // $600k into CA
        ]);

        // Fake the thresholds so the sweep asserts the alert wiring, not the network source.
        $threshold = new EconomicNexusThreshold(500_000, null, NexusCombinator::SalesOnly);
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([
            'US-CA' => $threshold, 'US-NY' => $threshold,
        ]));
        $this->app->forgetInstance(NexusEngine::class);
    }

    public function test_sweep_records_each_crossing_once_and_emails_recipients(): void
    {
        Mail::fake();
        config(['billing.nexus.alerts.recipients' => ['ops@example.test']]);
        $this->triggeredScenario();

        $newly = $this->app->make(NexusAlertEmitter::class)->sweep();

        // US-CA triggered is recorded and emailed; US-NY (a held registration) is not alerted.
        // The mail is queued (ShouldQueue) so delivery is retryable and decoupled from the sweep.
        $this->assertSame(['US-CA'], array_map(static fn ($e) => $e->state->value, $newly));
        $this->assertSame(1, NexusAlertDispatch::query()->where('subdivision', 'US-CA')->count());
        Mail::assertQueued(NexusAlertMail::class, 1);

        // A second sweep surfaces nothing new and sends no further mail — the ledger deduplicates.
        $again = $this->app->make(NexusAlertEmitter::class)->sweep();

        $this->assertSame([], $again);
        Mail::assertQueued(NexusAlertMail::class, 1);
    }

    public function test_sweep_records_the_crossing_but_sends_no_mail_without_recipients(): void
    {
        Mail::fake();
        config(['billing.nexus.alerts.recipients' => []]);
        $this->triggeredScenario();

        $newly = $this->app->make(NexusAlertEmitter::class)->sweep();

        $this->assertSame(['US-CA'], array_map(static fn ($e) => $e->state->value, $newly));
        $this->assertSame(1, NexusAlertDispatch::query()->where('subdivision', 'US-CA')->count());
        Mail::assertNothingOutgoing();
    }

    public function test_sweep_is_disabled_by_config(): void
    {
        Mail::fake();
        config(['billing.nexus.alerts.enabled' => false]);
        $this->triggeredScenario();

        $this->assertSame([], $this->app->make(NexusAlertEmitter::class)->sweep());
        $this->assertSame(0, NexusAlertDispatch::query()->count());
        Mail::assertNothingOutgoing();
    }

    public function test_a_deployment_whose_thresholds_cannot_be_resolved_alerts_instead_of_going_silent(): void
    {
        Mail::fake();
        config(['billing.nexus.alerts.recipients' => ['ops@example.test']]);

        $this->triggeredScenario();

        // The real-world shape of this: the dataset mirror serves a layout the consumer does not
        // read, so EVERY state resolves null and the engine reports Unknown rather than claiming
        // Below. An empty threshold source reproduces it exactly.
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([]));
        $this->app->forgetInstance(NexusEngine::class);

        $newly = $this->app->make(NexusAlertEmitter::class)->sweep();

        // Before the fix the sweep read only triggered() and approaching(), so this deployment
        // sent NOTHING — and a seller reads silence from a nexus monitor as "nothing to do"
        // while their thresholds are going unmeasured.
        $this->assertNotSame([], $newly, 'An unresolvable threshold must alert, not go quiet.');
        $this->assertSame('unknown', $newly[0]->status->value);

        Mail::assertQueued(NexusAlertMail::class, 1);
        $this->assertSame(1, NexusAlertDispatch::query()->where('status', 'unknown')->count());
    }

    public function test_an_unknown_state_alerts_only_once_per_period(): void
    {
        Mail::fake();
        config(['billing.nexus.alerts.recipients' => ['ops@example.test']]);

        $this->triggeredScenario();
        $this->app->singleton(NexusThresholdSource::class, fn (): NexusThresholdSource => new ArrayNexusThresholdSource([]));
        $this->app->forgetInstance(NexusEngine::class);

        $emitter = $this->app->make(NexusAlertEmitter::class);

        $this->assertNotSame([], $emitter->sweep());
        // A broken mirror stays broken, and the sweep runs daily — without the idempotency
        // ledger covering Unknown too, that would be a mail every morning until someone fixed it.
        $this->assertSame([], $emitter->sweep(), 'The same unknown state must not re-alert.');
    }
}
