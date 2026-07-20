<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Statistics;

/**
 * A two-proportion z-test — the simple, correct, well-understood significance signal for an A/B
 * conversion experiment. It asks: is the difference between a variant's conversion rate and the
 * control's larger than sampling noise would plausibly produce if they were really equal?
 *
 * The pooled two-proportion z-statistic:
 *
 *   p̂ = (c₁ + c₀) / (n₁ + n₀)                         (pooled rate under H₀: p₁ = p₀)
 *   SE = sqrt( p̂ (1 − p̂) (1/n₁ + 1/n₀) )               (standard error of the difference)
 *   z  = (c₁/n₁ − c₀/n₀) / SE
 *
 * The two-sided p-value is `2 · (1 − Φ(|z|))`, where Φ is the standard-normal CDF, computed here
 * from `erf` via the Abramowitz & Stegun 7.1.26 rational approximation (max abs error ≈ 1.5e-7 —
 * far tighter than any experiment needs). Confidence is `1 − p`.
 *
 * Everything is pure arithmetic on the counts, so the numbers are deterministic and exactly
 * assertable in a test. The honest caveats about what this signal does and does NOT license live
 * on {@see SignificanceSignal}.
 */
readonly class TwoProportionZTest
{
    /** The conventional significance threshold (95% confidence ⇒ α = 0.05, two-sided). */
    private const float DEFAULT_CONFIDENCE_THRESHOLD = 0.95;

    /**
     * Compare a variant `(conversions, impressions)` to the control's. Returns an undetermined
     * signal when either arm has no impressions or both rates are 0/100% with no variance to test.
     */
    public function compare(
        int $variantConversions,
        int $variantImpressions,
        int $controlConversions,
        int $controlImpressions,
        float $confidenceThreshold = self::DEFAULT_CONFIDENCE_THRESHOLD,
    ): SignificanceSignal {
        if ($variantImpressions <= 0 || $controlImpressions <= 0) {
            return SignificanceSignal::undetermined();
        }

        $n1 = (float) $variantImpressions;
        $n0 = (float) $controlImpressions;
        $p1 = $variantConversions / $n1;
        $p0 = $controlConversions / $n0;

        $pooled = ($variantConversions + $controlConversions) / ($n1 + $n0);
        $standardError = sqrt($pooled * (1.0 - $pooled) * (1.0 / $n1 + 1.0 / $n0));

        // No variance to test (both arms 0% or both 100%): the difference is exactly zero and the
        // SE is zero — there is nothing to distinguish, so report an honest undetermined signal.
        if ($standardError <= 0.0) {
            return SignificanceSignal::undetermined();
        }

        $z = ($p1 - $p0) / $standardError;
        $pValue = 2.0 * (1.0 - $this->normalCdf(abs($z)));
        $pValue = max(0.0, min(1.0, $pValue));
        $confidence = 1.0 - $pValue;

        return new SignificanceSignal(
            zScore: $z,
            pValue: $pValue,
            confidence: $confidence,
            significant: $confidence >= $confidenceThreshold,
        );
    }

    /** The standard-normal CDF Φ(x) = ½(1 + erf(x/√2)). */
    private function normalCdf(float $x): float
    {
        return 0.5 * (1.0 + $this->erf($x / M_SQRT2));
    }

    /**
     * The error function via Abramowitz & Stegun 7.1.26 (a rational × Gaussian approximation).
     * Odd, so erf(−x) = −erf(x); computed on |x| and sign-restored. Max absolute error ≈ 1.5e-7.
     */
    private function erf(float $x): float
    {
        $sign = $x < 0.0 ? -1.0 : 1.0;
        $x = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $y = 1.0 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }
}
