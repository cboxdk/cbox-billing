<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\RetryPlan;
use Carbon\CarbonImmutable;

/**
 * The adaptive retry schedule — the replacement for the old fixed `[1,3,5,7]` day schedule.
 * Given the decline category of a failed charge, it resolves the per-category {@see RetryPlan}
 * and computes WHEN each attempt should fire, applying the timing heuristics (spread,
 * weekend-avoidance, payday alignment, a max window). Deterministic: every instant is a pure
 * function of the first-failure instant and the plan, so a test clock drives it exactly.
 *
 * Kept behind a contract so the retry service depends on the scheduling behaviour, not the
 * concrete curve, and the console strategy editor can swap the backing plan store.
 */
interface SchedulesRetries
{
    /** The effective (config + override) recovery plan for a decline category. */
    public function planFor(DeclineCategory $category): RetryPlan;

    /**
     * The instant attempt `$attempt` (1-based) should fire for a charge whose category is
     * `$category` and first failed at `$firstFailedAt` — or null when the category is not
     * retried, the attempt is past the ceiling, or the instant would fall outside the max
     * recovery window (the schedule is then exhausted).
     */
    public function attemptAt(DeclineCategory $category, int $attempt, CarbonImmutable $firstFailedAt): ?CarbonImmutable;
}
