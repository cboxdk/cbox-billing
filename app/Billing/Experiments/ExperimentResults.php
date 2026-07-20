<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Billing\Experiments\Statistics\SignificanceSignal;
use App\Billing\Experiments\Statistics\TwoProportionZTest;
use App\Billing\Experiments\ValueObjects\ExperimentResult;
use App\Billing\Experiments\ValueObjects\VariantResult;
use App\Models\Experiment;
use App\Models\ExperimentConversion;
use App\Models\ExperimentImpression;
use App\Models\ExperimentVariant;

/**
 * The results read model: for a given experiment it counts, per variant, impressions and
 * conversions of the experiment's primary metric, derives the conversion rate, the lift over the
 * control, and the two-proportion-z significance signal, and assembles an {@see ExperimentResult}.
 *
 * Counts come straight from the deduped `experiment_impressions` / `experiment_conversions` tables
 * (each already UNIQUE per visitor), grouped in two aggregate queries — so a variant's numbers are
 * exact and reproducible for a seeded dataset. The control is the baseline: its lift is null and
 * its own significance is undetermined (a thing is not compared to itself).
 */
readonly class ExperimentResults
{
    public function __construct(private TwoProportionZTest $zTest) {}

    public function for(Experiment $experiment): ExperimentResult
    {
        $experiment->loadMissing('variants');

        $impressions = $this->impressionCounts($experiment);
        $conversions = $this->conversionCounts($experiment);

        $control = $experiment->control();
        $controlImpressions = $control !== null ? ($impressions[$control->id] ?? 0) : 0;
        $controlConversions = $control !== null ? ($conversions[$control->id] ?? 0) : 0;
        $controlRate = $controlImpressions > 0 ? $controlConversions / $controlImpressions : null;

        $results = [];
        $totalImpressions = 0;
        $totalConversions = 0;

        foreach ($experiment->variants as $variant) {
            $variantImpressions = $impressions[$variant->id] ?? 0;
            $variantConversions = $conversions[$variant->id] ?? 0;
            $totalImpressions += $variantImpressions;
            $totalConversions += $variantConversions;

            $rate = $variantImpressions > 0 ? $variantConversions / $variantImpressions : 0.0;

            $results[] = new VariantResult(
                variant: $variant,
                isControl: $variant->is_control,
                impressions: $variantImpressions,
                conversions: $variantConversions,
                rate: $rate,
                lift: $this->lift($variant, $rate, $controlRate),
                significance: $this->significance(
                    $variant,
                    $variantConversions,
                    $variantImpressions,
                    $controlConversions,
                    $controlImpressions,
                ),
            );
        }

        return new ExperimentResult(
            metric: $experiment->primary_metric,
            variants: $results,
            totalImpressions: $totalImpressions,
            totalConversions: $totalConversions,
        );
    }

    /** The lift of a variant's rate over the control's, or null for control / no baseline. */
    private function lift(ExperimentVariant $variant, float $rate, ?float $controlRate): ?float
    {
        if ($variant->is_control || $controlRate === null || $controlRate <= 0.0) {
            return null;
        }

        return ($rate - $controlRate) / $controlRate;
    }

    private function significance(
        ExperimentVariant $variant,
        int $variantConversions,
        int $variantImpressions,
        int $controlConversions,
        int $controlImpressions,
    ): SignificanceSignal {
        if ($variant->is_control) {
            return SignificanceSignal::undetermined();
        }

        return $this->zTest->compare(
            $variantConversions,
            $variantImpressions,
            $controlConversions,
            $controlImpressions,
        );
    }

    /**
     * Impressions per variant id (deduped rows counted).
     *
     * @return array<int, int>
     */
    private function impressionCounts(Experiment $experiment): array
    {
        $rows = ExperimentImpression::query()
            ->where('experiment_id', $experiment->id)
            ->selectRaw('experiment_variant_id, count(*) as aggregate')
            ->groupBy('experiment_variant_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $aggregate = $row->getAttribute('aggregate');
            $counts[$row->experiment_variant_id] = is_numeric($aggregate) ? (int) $aggregate : 0;
        }

        return $counts;
    }

    /**
     * Conversions of the experiment's primary metric, per variant id.
     *
     * @return array<int, int>
     */
    private function conversionCounts(Experiment $experiment): array
    {
        $rows = ExperimentConversion::query()
            ->where('experiment_id', $experiment->id)
            ->where('kind', $experiment->primary_metric->value)
            ->selectRaw('experiment_variant_id, count(*) as aggregate')
            ->groupBy('experiment_variant_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $aggregate = $row->getAttribute('aggregate');
            $counts[$row->experiment_variant_id] = is_numeric($aggregate) ? (int) $aggregate : 0;
        }

        return $counts;
    }
}
