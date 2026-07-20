<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Notifications\Contracts\ManagesNotificationPreferences;
use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\UsageAlertEmitter;
use App\Billing\Reporting\UsageReport;
use App\Billing\TestMode\CapturedNotifications;
use App\Mail\UsageAlertMail;
use App\Models\Organization;
use App\Models\UsageAlertDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature gap #2 closed: the optional usage/overage alert. Crossing an included-allowance
 * threshold queues exactly one branded alert per (org, meter, period, threshold) — suppressed
 * when the customer opts out, and captured (not delivered) in test mode.
 */
class UsageAlertTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $report */
    private function fakeUsage(array $report): void
    {
        $this->app->instance(UsageReport::class, new readonly class($report) extends UsageReport
        {
            /** @param array<string, mixed> $report */
            public function __construct(private array $report) {}

            public function forOrganization(Organization $organization): array
            {
                return $this->report;
            }
        });
    }

    /** @param array<string, mixed> $meterOverrides */
    private function report(string $periodStart = '2026-07-01', array $meterOverrides = []): array
    {
        return [
            'period_start' => $periodStart,
            'period_end' => '2026-07-31',
            'meters' => [array_merge([
                'key' => 'api_calls', 'name' => 'API requests', 'unit' => 'requests',
                'enabled' => true, 'unlimited' => false, 'used' => 8_400, 'allowance' => 10_000, 'percent' => 84,
            ], $meterOverrides)],
        ];
    }

    private function org(string $id = 'org_usage'): Organization
    {
        return Organization::query()->create([
            'id' => $id, 'name' => ucfirst($id), 'billing_email' => $id.'@example.test', 'billing_country' => 'DK',
        ]);
    }

    public function test_an_unresolvable_period_does_not_alert_or_record(): void
    {
        Mail::fake();
        // No resolvable billing period (empty period_start → empty period_key): there is no stable
        // idempotency key, so the crossing must neither email nor write a dispatch row.
        $this->fakeUsage($this->report(periodStart: ''));
        $org = $this->org();

        $this->assertSame(0, app(UsageAlertEmitter::class)->forOrganization($org));

        Mail::assertNothingQueued();
        $this->assertSame(0, UsageAlertDispatch::query()->count());
    }

    public function test_crossing_a_threshold_queues_exactly_one_alert_and_is_idempotent(): void
    {
        Mail::fake();
        $this->fakeUsage($this->report());
        $org = $this->org();

        $emitter = app(UsageAlertEmitter::class);

        $this->assertSame(1, $emitter->forOrganization($org), 'The first crossing queues one alert.');
        // Re-running the sweep must NOT re-queue — idempotent per (org, meter, period, threshold).
        $this->assertSame(0, $emitter->forOrganization($org), 'A second sweep in the same period queues nothing.');

        Mail::assertQueued(UsageAlertMail::class, 1);
        Mail::assertQueued(UsageAlertMail::class, static fn (UsageAlertMail $mail): bool => $mail->thresholdPercent === 80 && $mail->meterName === 'API requests');

        $this->assertDatabaseHas('usage_alert_dispatches', [
            'organization_id' => 'org_usage', 'meter_key' => 'api_calls', 'period_key' => '2026-07-01', 'threshold' => 80,
        ]);
    }

    public function test_a_new_period_fires_a_fresh_alert(): void
    {
        Mail::fake();
        $org = $this->org();

        // Re-resolve the emitter after each rebind so it reads the current period's usage.
        $this->fakeUsage($this->report(periodStart: '2026-07-01'));
        app(UsageAlertEmitter::class)->forOrganization($org);

        // A new billing period (fresh allowance) — the threshold can fire again.
        $this->fakeUsage($this->report(periodStart: '2026-08-01'));
        $this->assertSame(1, app(UsageAlertEmitter::class)->forOrganization($org));

        Mail::assertQueued(UsageAlertMail::class, 2);
    }

    public function test_a_single_run_past_both_thresholds_emails_only_the_highest(): void
    {
        Mail::fake();
        $this->fakeUsage($this->report(meterOverrides: ['used' => 12_000, 'percent' => 120]));
        $org = $this->org();

        $this->assertSame(1, app(UsageAlertEmitter::class)->forOrganization($org));

        Mail::assertQueued(UsageAlertMail::class, 1);
        Mail::assertQueued(UsageAlertMail::class, static fn (UsageAlertMail $mail): bool => $mail->thresholdPercent === 100);
        // Both thresholds are recorded, so no straggler 80% fires on a later sweep.
        $this->assertSame(2, UsageAlertDispatch::query()->count());
    }

    public function test_an_opted_out_customer_is_suppressed(): void
    {
        Mail::fake();
        $this->fakeUsage($this->report());
        $org = $this->org();

        app(ManagesNotificationPreferences::class)->setOptedIn('org_usage', MailEventType::UsageAlert, false);

        app(UsageAlertEmitter::class)->forOrganization($org);

        Mail::assertNotQueued(UsageAlertMail::class);
    }

    public function test_test_mode_captures_instead_of_delivering(): void
    {
        Mail::fake();
        app(BillingContext::class)->setMode(BillingMode::Test);
        $this->fakeUsage($this->report());
        $org = $this->org('org_sandbox');

        app(UsageAlertEmitter::class)->forOrganization($org);

        // Test mode never delivers — the alert is captured in the sandbox sink.
        Mail::assertNothingQueued();
        $this->assertSame(1, app(CapturedNotifications::class)->count());
    }

    public function test_an_unlimited_or_under_threshold_meter_does_not_alert(): void
    {
        Mail::fake();
        $org = $this->org();

        // Unlimited meter (no allowance to cross) — re-resolve so each reads its own report.
        $this->fakeUsage($this->report(meterOverrides: ['unlimited' => true, 'allowance' => null]));
        $this->assertSame(0, app(UsageAlertEmitter::class)->forOrganization($org));

        // Under the lowest threshold.
        $this->fakeUsage($this->report(meterOverrides: ['used' => 5_000, 'percent' => 50]));
        $this->assertSame(0, app(UsageAlertEmitter::class)->forOrganization($org));

        Mail::assertNothingQueued();
    }
}
