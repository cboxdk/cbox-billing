<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The single source of truth for "what billing period / cycle is a subscription in?" — the
 * period-anchor idiom (`current_period_start ?? now()->startOfMonth()`) and the
 * plan-interval → {@see BillingCycle} construction that were copy-pasted across the
 * subscription, renewal, depth, retirement, retention and metering services.
 *
 * Every caller passes its OWN clock's `now` (the container clock, a test clock, or wall time)
 * so the fallback stays faithful to that caller's time source; the resolver only centralizes the
 * SHAPE of the derivation, never picks the clock. "Is this plan yearly?" resolves through the one
 * canonical {@see Plan::billingInterval()} — no local interval remapping.
 */
readonly class SubscriptionPeriods
{
    /**
     * The subscription's current period START: its stored anchor, else the calendar-month start
     * of `$now` (the deny-nothing fallback for a subscription that has not been through a cycle).
     */
    public static function currentStart(Subscription $subscription, DateTimeInterface $now): Carbon
    {
        return $subscription->current_period_start ?? Carbon::instance($now)->startOfMonth();
    }

    /**
     * The subscription's current period END: its stored anchor, else the calendar-month end of
     * `$now` — the paired fallback to {@see currentStart()}.
     */
    public static function currentEnd(Subscription $subscription, DateTimeInterface $now): Carbon
    {
        return $subscription->current_period_end ?? Carbon::instance($now)->endOfMonth();
    }

    /**
     * The {@see BillingCycle} a subscription bills on: anchored on its current period start, at the
     * plan's canonical interval. Replaces the per-service `cycleFor`/`intervalFor` duplicates.
     */
    public static function cycleFor(Subscription $subscription, Plan $plan, DateTimeInterface $now): BillingCycle
    {
        $start = self::currentStart($subscription, $now);

        return new BillingCycle(
            anchorDay: (int) $start->format('j'),
            anchorMonth: (int) $start->format('n'),
            interval: $plan->billingInterval(),
            zone: new DateTimeZone('UTC'),
        );
    }

    /**
     * The OPENING billing period for a NEW subscription on `$plan` as of `$now`. A yearly plan
     * opens a full year anchored on the signup day (so it does not renew at the next month
     * boundary); a monthly plan keeps calendar-month alignment (everyone renews on the 1st).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function opening(Plan $plan, DateTimeInterface $now): array
    {
        $nowCarbon = Carbon::instance($now);

        if ($plan->billingInterval() === BillingInterval::Yearly) {
            $cycle = BillingCycle::anchoredOnSignup($nowCarbon->toDateTimeImmutable(), BillingInterval::Yearly);
            $period = $cycle->periodContaining($nowCarbon->toDateTimeImmutable());

            return [Carbon::instance($period->start), Carbon::instance($period->end)];
        }

        return [$nowCarbon->copy()->startOfMonth(), $nowCarbon->copy()->endOfMonth()];
    }
}
