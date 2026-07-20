<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Subscriptions\SubscriptionPeriods;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The one shared period/cycle resolver every subscription service now routes through — so the
 * `current_period_start ?? startOfMonth()` anchor and "is this plan yearly?" have a single
 * definition. Unsaved models, no DB.
 */
class SubscriptionPeriodsTest extends TestCase
{
    private function subscription(?string $start = null, ?string $end = null): Subscription
    {
        $sub = new Subscription;
        $sub->current_period_start = $start !== null ? Carbon::parse($start) : null;
        $sub->current_period_end = $end !== null ? Carbon::parse($end) : null;

        return $sub;
    }

    public function test_current_start_prefers_the_stored_anchor(): void
    {
        $start = SubscriptionPeriods::currentStart($this->subscription('2026-03-10'), new CarbonImmutable('2026-07-20'));

        $this->assertSame('2026-03-10', $start->toDateString());
    }

    public function test_current_start_falls_back_to_the_month_start_of_now(): void
    {
        $start = SubscriptionPeriods::currentStart($this->subscription(), new CarbonImmutable('2026-07-20 13:00'));

        $this->assertSame('2026-07-01 00:00:00', $start->toDateTimeString());
    }

    public function test_current_end_falls_back_to_the_month_end_of_now(): void
    {
        $end = SubscriptionPeriods::currentEnd($this->subscription(), new CarbonImmutable('2026-07-20 13:00'));

        $this->assertSame('2026-07-31 23:59:59', $end->toDateTimeString());
    }

    public function test_cycle_reads_the_interval_from_the_canonical_plan_billing_interval(): void
    {
        // A legacy stored spelling ('annual') still resolves yearly through Plan::billingInterval().
        $plan = new Plan(['interval' => 'annual']);
        $cycle = SubscriptionPeriods::cycleFor($this->subscription('2026-03-10'), $plan, new CarbonImmutable('2026-07-20'));

        $this->assertSame(BillingInterval::Yearly, $cycle->interval);
        $this->assertSame(10, $cycle->anchorDay);
        $this->assertSame(3, $cycle->anchorMonth);
    }

    public function test_opening_a_monthly_plan_is_the_calendar_month(): void
    {
        $plan = new Plan(['interval' => 'month']);
        [$start, $end] = SubscriptionPeriods::opening($plan, new CarbonImmutable('2026-07-20 09:30'));

        $this->assertSame('2026-07-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-31 23:59:59', $end->toDateTimeString());
    }

    public function test_opening_a_yearly_plan_anchors_a_full_year_on_the_signup_day(): void
    {
        $plan = new Plan(['interval' => 'year']);
        [$start, $end] = SubscriptionPeriods::opening($plan, new CarbonImmutable('2026-07-20'));

        // A full year, not a calendar month — the yearly plan does not renew at the next month.
        $this->assertSame('2026-07-20', $start->toDateString());
        $this->assertTrue($end->greaterThan($start->copy()->addMonths(11)));
    }
}
