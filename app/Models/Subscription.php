<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organization's subscription to a plan. `status` is cast to the engine's
 * {@see SubscriptionStatus}; the current period bounds the live billing window.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property int $seats
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 */
class Subscription extends Model
{
    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seats',
        'current_period_start', 'current_period_end', 'cancel_at_period_end',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'seats' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
