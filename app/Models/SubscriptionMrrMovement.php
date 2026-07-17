<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Reporting\MrrMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded change in a subscription's contributing monthly-recurring amount — the
 * atomic input to the analytics MRR-movement waterfall. Append-only: a row is written at
 * each lifecycle point where the contributing MRR actually moves (subscribe, trial
 * conversion, plan/seat change, cancel, reactivation), carrying the amount before
 * (`previous_mrr_minor`) and after (`new_mrr_minor`) and the classified {@see $kind}.
 *
 * `kind` mirrors the engine {@see MrrMovement} decomposition:
 * `new` (0→+) · `reactivation` (0→+, returning) · `expansion` (+→larger) ·
 * `contraction` (+→smaller) · `churn` (+→0).
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string $organization_id
 * @property string $currency
 * @property Carbon $occurred_at
 * @property int $previous_mrr_minor
 * @property int $new_mrr_minor
 * @property string $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubscriptionMrrMovement extends Model
{
    public const KIND_NEW = 'new';

    public const KIND_EXPANSION = 'expansion';

    public const KIND_CONTRACTION = 'contraction';

    public const KIND_CHURN = 'churn';

    public const KIND_REACTIVATION = 'reactivation';

    protected $fillable = [
        'subscription_id', 'organization_id', 'currency', 'occurred_at',
        'previous_mrr_minor', 'new_mrr_minor', 'kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'previous_mrr_minor' => 'integer',
            'new_mrr_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
