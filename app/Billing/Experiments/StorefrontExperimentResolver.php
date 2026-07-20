<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\ValueObjects\ServedTable;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\PricingTable;

/**
 * Decides what a public `/pricing/{key}` request serves, once experiments are considered. Given
 * the base table the key resolves to and the request's anonymous visitor id, it returns a
 * {@see ServedTable} describing the table to present and whether an impression/attribution
 * applies:
 *
 *  1. A **running** experiment on the base table → assign the visitor a variant
 *     ({@see VariantAssigner}) and serve that variant's table (its `served_pricing_table_id`, or
 *     the base table when the variant points nowhere — the control default). Records an impression.
 *  2. Else a **concluded** experiment that promoted a winner → serve the winner's table as the new
 *     canonical page (no assignment, no impression — the experiment is over but the winning design
 *     stays live).
 *  3. Else → serve the base table unchanged.
 *
 * Deny-by-default: a draft experiment, a concluded experiment with no promoted winner, or a
 * running experiment whose assignment fails (no positively-weighted variant) all fall through to
 * the plain base table — the storefront never breaks because an experiment is misconfigured.
 */
readonly class StorefrontExperimentResolver
{
    public function __construct(
        private VariantAssigner $assigner,
        private ImpressionRecorder $impressions,
    ) {}

    public function resolve(PricingTable $base, string $visitorId): ServedTable
    {
        $running = $this->runningExperimentFor($base);

        if ($running instanceof Experiment) {
            $variant = $this->assigner->assign($running, $visitorId);

            if ($variant instanceof ExperimentVariant) {
                $this->impressions->record($running, $variant, $visitorId);

                return ServedTable::assigned($this->tableFor($base, $variant), $running, $variant);
            }
        }

        $promoted = $this->promotedExperimentFor($base);

        if ($promoted instanceof Experiment) {
            $winner = $promoted->promotedVariant;

            if ($winner instanceof ExperimentVariant) {
                return ServedTable::promoted($this->tableFor($base, $winner), $promoted, $winner);
            }
        }

        return ServedTable::plain($base);
    }

    /** The single running experiment on this base table (the earliest-started, if several). */
    private function runningExperimentFor(PricingTable $base): ?Experiment
    {
        return Experiment::query()
            ->with('variants.servedTable')
            ->where('pricing_table_id', $base->id)
            ->where('status', ExperimentStatus::Running->value)
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();
    }

    /** The most-recently-concluded experiment on this base table that promoted a winner. */
    private function promotedExperimentFor(PricingTable $base): ?Experiment
    {
        return Experiment::query()
            ->with('promotedVariant.servedTable')
            ->where('pricing_table_id', $base->id)
            ->where('status', ExperimentStatus::Concluded->value)
            ->whereNotNull('promoted_variant_id')
            ->orderByDesc('concluded_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The table a variant serves: its own pointer, or the base table when it points nowhere. */
    private function tableFor(PricingTable $base, ExperimentVariant $variant): PricingTable
    {
        $served = $variant->servedTable;

        return $served instanceof PricingTable ? $served : $base;
    }
}
