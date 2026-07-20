<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Experiments\Statistics\TwoProportionZTest;
use Tests\TestCase;

/**
 * The significance statistic — a pooled two-proportion z-test. The numbers are pure arithmetic on
 * the counts, so they are exactly assertable against a hand-computed reference.
 */
class TwoProportionZTestTest extends TestCase
{
    public function test_it_computes_the_exact_pooled_z_and_confidence_for_a_known_case(): void
    {
        // Control 10/100 = 10%, variant 20/100 = 20%.
        //   pooled p̂ = 30/200 = 0.15
        //   SE = sqrt(0.15 · 0.85 · (1/100 + 1/100)) = sqrt(0.00255) = 0.0504975247…
        //   z  = (0.20 − 0.10) / SE = 1.9802950…
        //   two-sided p = 2·(1 − Φ(1.98029)) ≈ 0.047704 → confidence ≈ 0.952296
        $signal = (new TwoProportionZTest)->compare(20, 100, 10, 100);

        $this->assertEqualsWithDelta(1.9802950, $signal->zScore, 1e-4);
        $this->assertEqualsWithDelta(0.047704, $signal->pValue, 1e-4);
        $this->assertEqualsWithDelta(0.952296, $signal->confidence, 1e-4);
        $this->assertTrue($signal->significant, 'p < 0.05, so significant at 95%.');
        $this->assertSame(95, $signal->confidencePercent());
    }

    public function test_a_small_difference_is_not_significant(): void
    {
        // 51 vs 50 out of 100 each — well within noise.
        $signal = (new TwoProportionZTest)->compare(51, 100, 50, 100);

        $this->assertLessThan(0.5, $signal->zScore);
        $this->assertFalse($signal->significant);
        $this->assertLessThan(0.95, $signal->confidence);
    }

    public function test_a_negative_lift_yields_a_negative_z(): void
    {
        // The variant converts WORSE than control (5% vs 20%).
        $signal = (new TwoProportionZTest)->compare(5, 100, 20, 100);

        $this->assertLessThan(0.0, $signal->zScore);
        $this->assertTrue($signal->significant, 'A large negative difference is still significant.');
    }

    public function test_it_is_undetermined_without_a_sample_or_variance(): void
    {
        // No impressions on an arm.
        $this->assertFalse((new TwoProportionZTest)->compare(0, 0, 5, 100)->significant);
        $this->assertSame(0.0, (new TwoProportionZTest)->compare(0, 0, 5, 100)->confidence);

        // Both arms 0% — no variance to test.
        $noVariance = (new TwoProportionZTest)->compare(0, 100, 0, 100);
        $this->assertSame(0.0, $noVariance->zScore);
        $this->assertFalse($noVariance->significant);
    }
}
