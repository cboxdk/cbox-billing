<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recorded impression: an anonymous visitor was served a variant's pricing table. Deduped to
 * ONCE per visitor per variant by the UNIQUE `(experiment_variant_id, visitor_id)` constraint —
 * so refreshing the page or coming back later does not inflate the denominator. Privacy: the
 * `visitor_id` is an opaque, random anonymous id (a cookie), never a customer identifier; the
 * row holds no PII.
 *
 * @property int $id
 * @property int $experiment_id
 * @property int $experiment_variant_id
 * @property string $visitor_id
 * @property Carbon $first_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExperimentImpression extends Model
{
    protected $fillable = [
        'experiment_id', 'experiment_variant_id', 'visitor_id', 'first_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
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
