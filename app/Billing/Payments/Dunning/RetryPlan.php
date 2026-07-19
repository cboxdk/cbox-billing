<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

use App\Billing\Payments\Contracts\SchedulesRetries;

/**
 * The resolved recovery plan for one {@see DeclineCategory} — the effective, per-category
 * strategy after config defaults and any DB override are merged. It is the shape the
 * {@see SchedulesRetries} strategy computes instants from and
 * the console strategy editor reads/writes.
 *
 *  - `retry`         — whether this category is retried at all (a Hard decline is always false).
 *  - `backoffDays`   — day-offsets from the first failure, one per attempt; its length is the
 *                      attempt ceiling unless `maxAttempts` narrows it.
 *  - `maxAttempts`   — the retry ceiling (defaults to `count(backoffDays)`).
 *  - `avoidWeekends` — nudge a scheduled instant off Sat/Sun onto the next weekday.
 *  - `alignToPayday` — nudge a scheduled instant toward the next configured payday day-of-month
 *                      (for insufficient-funds recovery).
 */
readonly class RetryPlan
{
    /**
     * @param  list<int>  $backoffDays
     */
    public function __construct(
        public DeclineCategory $category,
        public bool $retry,
        public array $backoffDays,
        public int $maxAttempts,
        public bool $avoidWeekends,
        public bool $alignToPayday,
    ) {}
}
