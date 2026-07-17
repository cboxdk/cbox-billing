<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured retention event: a cancellation (immediate / period-end / pause) or a
 * win-back reactivation, with the customer's stated reason. Append-only — never updated
 * or deleted — so churn and win-back reasons remain queryable for retention analytics
 * independently of the subscription's current state.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string $organization_id
 * @property int|null $plan_id
 * @property string $mode
 * @property string|null $reason
 * @property string|null $feedback
 */
class SubscriptionCancellation extends Model
{
    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_PERIOD_END = 'period_end';

    public const MODE_PAUSE = 'pause';

    public const MODE_REACTIVATE = 'reactivate';

    protected $fillable = [
        'subscription_id', 'organization_id', 'plan_id', 'mode', 'reason', 'feedback',
    ];

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
