<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

use App\Billing\Experiments\Statistics\SignificanceSignal;
use App\Models\ExperimentVariant;

/**
 * The measured outcome of one experiment arm: its impressions, conversions (of the experiment's
 * primary metric), the conversion `rate`, the `lift` over the control, and the
 * {@see SignificanceSignal} from the two-proportion z-test against the control. The control's own
 * row carries a null lift and an undetermined signal (it is the baseline, not compared to itself).
 */
readonly class VariantResult
{
    public function __construct(
        public ExperimentVariant $variant,
        public bool $isControl,
        public int $impressions,
        public int $conversions,
        public float $rate,
        public ?float $lift,
        public SignificanceSignal $significance,
    ) {}

    /** The conversion rate as a percentage (0–100). */
    public function ratePercent(): float
    {
        return $this->rate * 100.0;
    }

    /** The lift over control as a percentage, or null for the control row / no baseline rate. */
    public function liftPercent(): ?float
    {
        return $this->lift === null ? null : $this->lift * 100.0;
    }
}
