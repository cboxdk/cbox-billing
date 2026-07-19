<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use App\Billing\Mode\BillingContext;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\ConvertsTrials;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Billing\TestMode\ValueObjects\AdvanceResult;
use App\Models\PaymentRetry;
use App\Models\Subscription;
use App\Models\TestClock;
use Carbon\CarbonImmutable;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Advances a test clock's virtual time and runs the due billing logic for the subscriptions
 * bound to it — exactly as it would have fired over real elapsed time, but in seconds. This is
 * how an integrator tests a year of renewals, a trial conversion, or a full dunning schedule
 * without waiting.
 *
 * It is EVENT-STEPPING: rather than jump straight to the target and stamp everything there, it
 * walks the clock to each due instant in order — the next renewal boundary, the next trial
 * end, the next dunning attempt — sets the virtual clock to exactly that instant, runs
 * everything due at it, then finds the next. So a monthly subscription advanced a year fires
 * twelve renewals on the right twelve dates, each invoice period-correct, via the SAME engine
 * path ({@see CycleRenewalService}, {@see ConvertsTrials}, {@see RetriesPayments}) the
 * scheduled passes use.
 *
 * Everything runs in TEST mode at the step's virtual time (via {@see BillingContext::runAtVirtualTime()}),
 * so it only touches `livemode = false` rows, charges route through the fake gateway, and mail
 * is captured not sent. It is deterministic and idempotent: each service is idempotent on its
 * own boundary, and re-advancing to a time already reached is a no-op (a renewal whose period
 * already rolled grants nothing new; a settled retry is not re-charged).
 */
readonly class TestClockAdvancer
{
    /** Safety cap on the number of virtual steps, so a pathological input cannot loop forever. */
    private const MAX_STEPS = 5000;

    public function __construct(
        private BillingContext $context,
        private CycleRenewalService $renewals,
        private ConvertsTrials $trials,
        private RetriesPayments $retries,
    ) {}

    public function advance(TestClock $clock, CarbonImmutable $target): AdvanceResult
    {
        $from = $clock->virtualNow();

        $counters = ['renewals' => 0, 'trials' => 0, 'dunning' => 0, 'invoices' => 0];

        // Deny-by-default: a clock only ever moves forward. Advancing to now-or-past is a no-op.
        if ($target <= $from) {
            return new AdvanceResult($from, $from, 0, 0, 0, 0, 0);
        }

        // Process anything due at exactly the current instant first (a boundary that lands on
        // the clock's start), then step strictly forward through each subsequent event. The
        // processing closures capture `$counters` BY REFERENCE (a regular closure, not an
        // arrow fn) so the tallies survive.
        $this->atVirtual($from, function () use ($clock, $from, &$counters): void {
            $this->processAt($clock, $from, $counters);
        });

        $current = $from;
        $steps = 0;

        while ($steps < self::MAX_STEPS) {
            $next = $this->atVirtual($current, fn (): ?CarbonImmutable => $this->nextEventTime($clock, $current, $target));

            if ($next === null) {
                $current = $target;
                break;
            }

            $this->atVirtual($next, function () use ($clock, $next, &$counters): void {
                $this->processAt($clock, $next, $counters);
            });

            $current = $next;
            $steps++;
        }

        $this->persist($clock, $current);

        return new AdvanceResult(
            $from,
            $current,
            $counters['renewals'],
            $counters['trials'],
            $counters['dunning'],
            $counters['invoices'],
            $steps,
        );
    }

    /**
     * Run every piece of billing logic due at exactly `$at` for the clock's bound
     * subscriptions, repeating until the instant is quiescent (so several attempts that all
     * fall on the same instant, or a cascade, all fire). Assumes the caller has already
     * entered test mode at `$at`.
     *
     * @param  array{renewals: int, trials: int, dunning: int, invoices: int}  $counters
     */
    private function processAt(TestClock $clock, CarbonImmutable $at, array &$counters): void
    {
        do {
            $fired = false;

            foreach ($this->dueTrials($clock, $at) as $subscription) {
                if ($this->trials->convertDue($subscription, $at) === ConvertsTrials::OUTCOME_CONVERTED) {
                    $counters['trials']++;
                    $fired = true;
                }
            }

            foreach ($this->dueRenewals($clock, $at) as $subscription) {
                $outcome = $this->renewals->renew($subscription, $at);

                if (! $outcome->baseRenewed && ! $outcome->canceled) {
                    continue;
                }

                $fired = true;

                if ($outcome->baseRenewed) {
                    $counters['renewals']++;
                }

                if ($outcome->invoice !== null) {
                    $counters['invoices']++;
                    $this->retries->chargeRenewal(
                        $outcome->invoice,
                        $subscription->refresh()->loadMissing('organization', 'plan'),
                    );
                }
            }

            foreach ($this->dueRetries($clock, $at) as $retry) {
                $this->retries->attempt($retry);
                $counters['dunning']++;
                $fired = true;
            }
        } while ($fired);
    }

    /**
     * The earliest billing event strictly after `$current` and no later than `$target` across
     * the clock's bound subscriptions: a renewal boundary, a trial end, or a due dunning
     * attempt. Null when nothing more is due before the target. Assumes test mode.
     */
    private function nextEventTime(TestClock $clock, CarbonImmutable $current, CarbonImmutable $target): ?CarbonImmutable
    {
        $subscriptionIds = $this->boundSubscriptionIds($clock);

        if ($subscriptionIds === []) {
            return null;
        }

        $candidates = [];

        $candidates[] = Subscription::query()
            ->whereIn('id', $subscriptionIds)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', $current)
            ->where('current_period_end', '<=', $target)
            ->min('current_period_end');

        $candidates[] = Subscription::query()
            ->whereIn('id', $subscriptionIds)
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNull('paused_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', $current)
            ->where('trial_ends_at', '<=', $target)
            ->min('trial_ends_at');

        $candidates[] = PaymentRetry::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('status', PaymentRetry::STATUS_RETRYING)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '>', $current)
            ->where('next_attempt_at', '<=', $target)
            ->min('next_attempt_at');

        $earliest = null;

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue; // a datetime `min()` yields a string; anything else is "no candidate".
            }

            $at = CarbonImmutable::parse($candidate);

            if ($earliest === null || $at < $earliest) {
                $earliest = $at;
            }
        }

        return $earliest;
    }

    /**
     * The active, non-paused bound subscriptions whose period has closed by `$at`.
     *
     * @return Collection<int, Subscription>
     */
    private function dueRenewals(TestClock $clock, CarbonImmutable $at)
    {
        return Subscription::query()
            ->where('test_clock_id', $clock->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $at)
            ->with(['organization', 'plan.prices', 'plan.product', 'plan.creditGrants', 'plan.entitlements.meter', 'addOns'])
            ->get();
    }

    /**
     * The trialing bound subscriptions whose trial has ended by `$at`.
     *
     * @return Collection<int, Subscription>
     */
    private function dueTrials(TestClock $clock, CarbonImmutable $at)
    {
        return Subscription::query()
            ->where('test_clock_id', $clock->id)
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNull('paused_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $at)
            ->with(['organization', 'plan'])
            ->get();
    }

    /**
     * The open dunning retries for the clock's bound subscriptions whose next attempt is due
     * by `$at`.
     *
     * @return Collection<int, PaymentRetry>
     */
    private function dueRetries(TestClock $clock, CarbonImmutable $at)
    {
        return PaymentRetry::query()
            ->whereIn('subscription_id', $this->boundSubscriptionIds($clock))
            ->where('status', PaymentRetry::STATUS_RETRYING)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $at)
            ->orderBy('id')
            ->get();
    }

    /** @return list<int> */
    private function boundSubscriptionIds(TestClock $clock): array
    {
        /** @var list<int> */
        return Subscription::query()
            ->where('test_clock_id', $clock->id)
            ->pluck('id')
            ->all();
    }

    /** Persist the clock's new virtual time (a model-key update, unaffected by the plane scope). */
    private function persist(TestClock $clock, CarbonImmutable $at): void
    {
        $clock->forceFill(['now_at' => Carbon::instance($at)])->save();
    }

    /**
     * Run `$callback` in test mode at virtual time `$at`.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atVirtual(CarbonImmutable $at, Closure $callback): mixed
    {
        return $this->context->runAtVirtualTime($at, $callback);
    }
}
