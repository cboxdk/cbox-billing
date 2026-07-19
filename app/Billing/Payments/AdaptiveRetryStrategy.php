<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\DunningStrategyRepository;
use App\Billing\Payments\Dunning\RetryPlan;
use App\Models\DunningStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The adaptive retry schedule — the decline-code-aware replacement for the fixed `[1,3,5,7]`
 * day schedule. It resolves a per-{@see DeclineCategory} {@see RetryPlan} (config defaults ⊕ any
 * DB override) and computes each attempt's instant, applying the recovery timing heuristics:
 *
 *  - PER-CATEGORY CURVES — a Hard decline is never retried; insufficient-funds is spread wider;
 *    do-not-honor / try-again-later gets a longer backoff; the rest ride the base curve.
 *  - SPREAD — attempts are day-offsets off the ORIGINAL failure, so the cadence is stable
 *    regardless of when a pass runs; a category's curve widens the gaps rather than hammering.
 *  - PAYDAY ALIGNMENT — an insufficient-funds attempt is nudged FORWARD to the next configured
 *    payday day-of-month, because retrying a short balance before payday just declines again.
 *  - WEEKEND AVOIDANCE — an attempt landing on Sat/Sun is pushed to the next weekday, so
 *    retries don't cluster on days banks post fewer transactions.
 *  - MAX WINDOW — an attempt that would fall beyond the recovery window is dropped (the
 *    schedule exhausts) rather than chasing a charge indefinitely.
 *
 * Every instant is a pure function of the first-failure instant + the resolved plan, so a test
 * clock drives the whole cadence deterministically.
 */
readonly class AdaptiveRetryStrategy implements SchedulesRetries
{
    public function __construct(
        private Config $config,
        private DunningStrategyRepository $overrides,
    ) {}

    public function planFor(DeclineCategory $category): RetryPlan
    {
        $override = $this->overrides->forCategory($category);

        $backoff = $this->backoffFor($category, $override);
        $retry = $category->isRecoverable() && $this->retryFlag($category, $override);

        // A category that is retryable but has an empty curve is degenerate — fall back to a
        // single next-day attempt so the flow is never left with no schedule.
        if ($retry && $backoff === []) {
            $backoff = [1];
        }

        $maxAttempts = $this->maxAttemptsFor($override, $backoff);

        return new RetryPlan(
            category: $category,
            retry: $retry,
            backoffDays: $backoff,
            maxAttempts: $maxAttempts,
            avoidWeekends: $this->boolKnob($category, $override, 'avoid_weekends', 'avoidWeekends'),
            alignToPayday: $this->boolKnob($category, $override, 'align_to_payday', 'alignToPayday'),
        );
    }

    public function attemptAt(DeclineCategory $category, int $attempt, CarbonImmutable $firstFailedAt): ?CarbonImmutable
    {
        $plan = $this->planFor($category);

        if (! $plan->retry || $attempt < 1 || $attempt > $plan->maxAttempts) {
            return null;
        }

        $offset = $plan->backoffDays[$attempt - 1] ?? null;

        if ($offset === null) {
            return null;
        }

        // Anchor off the failure instant (stable cadence), then apply the heuristics in order:
        // payday pull first (may push forward), then weekend avoidance, then the window clamp.
        $at = $firstFailedAt->addDays($offset);

        if ($plan->alignToPayday) {
            $at = $this->pullToPayday($at);
        }

        if ($plan->avoidWeekends) {
            $at = $this->pushOffWeekend($at);
        }

        $window = $firstFailedAt->addDays($this->maxWindowDays());

        if ($at->greaterThan($window)) {
            return null;
        }

        return $at;
    }

    /**
     * The resolved backoff curve: DB override → per-category config → the base curve (which
     * itself falls back to the legacy `payment.retry.schedule`, so an existing deployment keeps
     * its schedule until a category opts into its own).
     *
     * @return list<int>
     */
    private function backoffFor(DeclineCategory $category, ?DunningStrategy $override): array
    {
        if ($override !== null && $override->backoff_days !== []) {
            return $this->sanitizeDays($override->backoff_days);
        }

        $categoryDays = $this->config->get("billing.dunning.strategies.categories.{$category->value}.backoff_days");

        if (is_array($categoryDays) && $categoryDays !== []) {
            return $this->sanitizeDays($categoryDays);
        }

        return $this->baseCurve();
    }

    /**
     * The base curve every un-tuned category inherits.
     *
     * @return list<int>
     */
    private function baseCurve(): array
    {
        $default = $this->config->get('billing.dunning.strategies.default.backoff_days');

        if (is_array($default) && $default !== []) {
            return $this->sanitizeDays($default);
        }

        $legacy = $this->config->get('billing.payment.retry.schedule', [1, 3, 5, 7]);
        $days = $this->sanitizeDays(is_array($legacy) ? $legacy : []);

        return $days === [] ? [1, 3, 5, 7] : $days;
    }

    private function retryFlag(DeclineCategory $category, ?DunningStrategy $override): bool
    {
        if ($override !== null) {
            return $override->retry;
        }

        $configured = $this->config->get("billing.dunning.strategies.categories.{$category->value}.retry");

        return ! is_bool($configured) || $configured;
    }

    /** @param  list<int>  $backoff */
    private function maxAttemptsFor(?DunningStrategy $override, array $backoff): int
    {
        $ceiling = count($backoff);

        if ($override !== null && $override->max_attempts !== null && $override->max_attempts > 0) {
            return min($override->max_attempts, $ceiling);
        }

        return $ceiling;
    }

    private function boolKnob(DeclineCategory $category, ?DunningStrategy $override, string $configKey, string $overrideAttr): bool
    {
        if ($override !== null) {
            return (bool) $override->{$overrideAttr};
        }

        $configured = $this->config->get("billing.dunning.strategies.categories.{$category->value}.{$configKey}");

        return is_bool($configured) && $configured;
    }

    /** Nudge a date forward to the next configured payday day-of-month (inclusive of today). */
    private function pullToPayday(CarbonImmutable $at): CarbonImmutable
    {
        $paydays = $this->paydayDays();

        if ($paydays === []) {
            return $at;
        }

        // Scan up to ~two months of days for the nearest payday at or after `$at`; a month's
        // paydays always fall within that span, so this terminates.
        $cursor = $at;

        for ($i = 0; $i < 62; $i++) {
            if (in_array($cursor->day, $paydays, true)) {
                return $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $at;
    }

    /** Push a Saturday/Sunday (or any configured quiet weekday) onto the next non-quiet day. */
    private function pushOffWeekend(CarbonImmutable $at): CarbonImmutable
    {
        $quiet = $this->quietWeekdays();

        if ($quiet === []) {
            return $at;
        }

        $cursor = $at;

        for ($i = 0; $i < 7; $i++) {
            if (! in_array($cursor->isoWeekday(), $quiet, true)) {
                return $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $at;
    }

    private function maxWindowDays(): int
    {
        $value = $this->config->get('billing.dunning.strategies.max_window_days', 30);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 30;
    }

    /** @return list<int> */
    private function paydayDays(): array
    {
        $value = $this->config->get('billing.dunning.strategies.payday_days', [1, 15]);

        return $this->sanitizeDays(is_array($value) ? $value : []);
    }

    /** @return list<int> ISO-8601 weekday numbers (1=Mon … 7=Sun). */
    private function quietWeekdays(): array
    {
        $value = $this->config->get('billing.dunning.strategies.quiet_weekdays', [6, 7]);

        $out = [];

        foreach (is_array($value) ? $value : [] as $day) {
            if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 7) {
                $out[] = (int) $day;
            }
        }

        return $out;
    }

    /**
     * Coerce a configured/overridden day list to a clean, positive, ordered list<int>.
     *
     * @param  array<array-key, mixed>  $days
     * @return list<int>
     */
    private function sanitizeDays(array $days): array
    {
        $out = [];

        foreach ($days as $day) {
            if (is_numeric($day) && (int) $day > 0) {
                $out[] = (int) $day;
            }
        }

        return $out;
    }
}
