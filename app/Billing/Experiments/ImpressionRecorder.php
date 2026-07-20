<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Models\Experiment;
use App\Models\ExperimentImpression;
use App\Models\ExperimentVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Records that an anonymous visitor was served a variant's pricing table — deduped to once per
 * `(variant, visitor)`. Dedup is enforced two ways so a burst of concurrent first-views can't
 * double-count: a cheap pre-check, and the UNIQUE `(experiment_variant_id, visitor_id)` index as
 * the real backstop (a lost race hits the constraint and is swallowed). Recording an impression
 * is best-effort and must never break serving the page, so any write failure is contained.
 */
readonly class ImpressionRecorder
{
    public function record(Experiment $experiment, ExperimentVariant $variant, string $visitorId): void
    {
        $exists = ExperimentImpression::query()
            ->where('experiment_variant_id', $variant->id)
            ->where('visitor_id', $visitorId)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            ExperimentImpression::query()->create([
                'experiment_id' => $experiment->id,
                'experiment_variant_id' => $variant->id,
                'visitor_id' => $visitorId,
                'first_seen_at' => Carbon::now(),
            ]);
        } catch (QueryException) {
            // A concurrent first-view won the race and inserted the unique row already — the
            // impression is recorded exactly once, which is the invariant we wanted.
        }
    }
}
