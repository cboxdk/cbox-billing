<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

use App\Billing\Experiments\Enums\ExperimentMetric;

/**
 * The full results read model for an experiment: the primary metric measured, the per-arm
 * {@see VariantResult}s (control first), and the totals. {@see leader()} is the highest-rate
 * non-control arm whose signal is significant — the candidate winner the console offers to
 * promote — or null when nothing has separated from the control yet.
 */
readonly class ExperimentResult
{
    /**
     * @param  list<VariantResult>  $variants
     */
    public function __construct(
        public ExperimentMetric $metric,
        public array $variants,
        public int $totalImpressions,
        public int $totalConversions,
    ) {}

    /**
     * The candidate winner: the significant non-control arm with the highest conversion rate, or
     * null when no arm has beaten the control with confidence. Deliberately conservative — a lead
     * that is not yet significant does not surface a winner (the console still lets an operator
     * promote any arm manually, with eyes open).
     */
    public function leader(): ?VariantResult
    {
        $leader = null;

        foreach ($this->variants as $result) {
            if ($result->isControl || ! $result->significance->significant) {
                continue;
            }

            if ($result->lift === null || $result->lift <= 0.0) {
                continue;
            }

            if ($leader === null || $result->rate > $leader->rate) {
                $leader = $result;
            }
        }

        return $leader;
    }

    /** The control arm's result (the baseline), or null if the experiment somehow has none. */
    public function control(): ?VariantResult
    {
        foreach ($this->variants as $result) {
            if ($result->isControl) {
                return $result;
            }
        }

        return null;
    }
}
