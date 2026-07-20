<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\Exceptions\ExperimentActionDenied;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use Illuminate\Support\Carbon;

/**
 * The experiment lifecycle: start a draft running, and conclude a running experiment — optionally
 * promoting a winning variant.
 *
 * - **start** — draft → running. Re-validates that a control + a positively-weighted variant set
 *   exist (the same invariant authoring enforces) so a running experiment can always assign, then
 *   stamps `started_at`. Once running, the storefront begins bucketing visitors and accruing
 *   impressions/conversions.
 * - **conclude** — running → concluded, stamping `concluded_at`. A `winner` may be named: it must
 *   belong to this experiment, and setting it re-points the base `/pricing/{key}` page at the
 *   winner's table permanently ({@see StorefrontExperimentResolver} serves a concluded
 *   experiment's promoted variant). Concluding with no winner simply stops the test and the page
 *   reverts to its plain base table. Promotion is non-destructive — it stores a pointer, it does
 *   not mutate or move any pricing table, so it can be re-pointed or cleared later.
 */
readonly class ExperimentLifecycle
{
    public function start(Experiment $experiment): Experiment
    {
        if ($experiment->status !== ExperimentStatus::Draft) {
            throw ExperimentActionDenied::notDraft();
        }

        $experiment->loadMissing('variants');
        $this->assertServable($experiment);

        $experiment->forceFill([
            'status' => ExperimentStatus::Running->value,
            'started_at' => Carbon::now(),
            'promoted_variant_id' => null,
        ])->save();

        return $experiment;
    }

    public function conclude(Experiment $experiment, ?ExperimentVariant $winner = null): Experiment
    {
        if ($experiment->status !== ExperimentStatus::Running) {
            throw ExperimentActionDenied::notRunning();
        }

        if ($winner instanceof ExperimentVariant && $winner->experiment_id !== $experiment->id) {
            throw ExperimentActionDenied::foreignWinner();
        }

        $experiment->forceFill([
            'status' => ExperimentStatus::Concluded->value,
            'concluded_at' => Carbon::now(),
            'promoted_variant_id' => $winner?->id,
        ])->save();

        return $experiment;
    }

    /** A running experiment must have a control and at least one positively-weighted arm. */
    private function assertServable(Experiment $experiment): void
    {
        if ($experiment->control() === null) {
            throw ExperimentActionDenied::needsControl();
        }

        $totalWeight = 0;

        foreach ($experiment->variants as $variant) {
            $totalWeight += max(0, $variant->weight);
        }

        if ($totalWeight <= 0) {
            throw ExperimentActionDenied::zeroWeight();
        }
    }
}
