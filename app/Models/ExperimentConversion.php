<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Experiments\Enums\ExperimentMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recorded conversion attributed to a variant: a visitor who was assigned that variant took
 * the measured action. The `kind` is an {@see ExperimentMetric} — a `checkout_started` row when
 * the checkout session was minted, a `checkout_completed` row when it settled. Deduped to ONCE
 * per visitor per variant per kind by the UNIQUE `(experiment_variant_id, visitor_id, kind)`
 * constraint — so a re-delivered settlement webhook (or a double checkout-start) never
 * double-counts. `billing_session_id` joins a conversion back to the hosted checkout that
 * carried the attribution, which is how a settlement is matched to its earlier start.
 *
 * Privacy: `visitor_id` is the same opaque anonymous cookie id as the impression; no PII.
 *
 * @property int $id
 * @property int $experiment_id
 * @property int $experiment_variant_id
 * @property string $visitor_id
 * @property ExperimentMetric $kind
 * @property string|null $billing_session_id
 * @property Carbon $converted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExperimentConversion extends Model
{
    protected $fillable = [
        'experiment_id', 'experiment_variant_id', 'visitor_id', 'kind', 'billing_session_id', 'converted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ExperimentMetric::class,
            'converted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Experiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /** @return BelongsTo<ExperimentVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ExperimentVariant::class, 'experiment_variant_id');
    }
}
