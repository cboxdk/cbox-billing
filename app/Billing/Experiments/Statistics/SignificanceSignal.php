<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Statistics;

/**
 * The result of a two-proportion z-test comparing a variant's conversion rate to the control's:
 * the z-statistic, the two-sided p-value, and the confidence `(1 - p)`. `significant` is the
 * conventional call at the configured threshold (95% by default).
 *
 * HONEST CAVEAT — this is a guide, not a verdict. The z-test is a fixed-horizon frequentist test:
 * its p-value is only valid if you fix the sample size in advance and read it ONCE. Watching the
 * dashboard and stopping the moment it crosses 95% ("peeking") inflates the false-positive rate
 * well beyond 5%. It also assumes independent visitors and a reasonably large sample (the normal
 * approximation to the binomial); with only a handful of conversions the number is noise. Treat a
 * green signal as "worth a closer look", not "ship it" — see the docs for the full caveats.
 */
readonly class SignificanceSignal
{
    public function __construct(
        public float $zScore,
        public float $pValue,
        public float $confidence,
        public bool $significant,
    ) {}

    /** A not-computable signal (no control, or a zero-sized sample) — honestly "unknown". */
    public static function undetermined(): self
    {
        return new self(0.0, 1.0, 0.0, false);
    }

    /** The confidence as a whole-number percentage, for the console badge. */
    public function confidencePercent(): int
    {
        return (int) round($this->confidence * 100);
    }
}
