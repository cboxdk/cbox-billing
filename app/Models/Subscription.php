<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organization's subscription to a plan. `status` is cast to the engine's
 * {@see SubscriptionStatus}; the current period bounds the live billing window.
 *
 * Subscription-management depth (ADR-0012): `paused_at` marks an app-layer pause
 * (access + metering suspended, no renewal) the engine's two-state status cannot; a
 * `pending_plan` + `pending_effective_at` carry a deferred change-at-period-end; and the
 * `addOns` are the extra recurring charges attached to it, aligned or independent.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property int $seats
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $paused_at
 * @property int|null $pending_plan_id
 * @property Carbon|null $pending_effective_at
 */
class Subscription extends Model
{
    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seats',
        'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'paused_at', 'pending_plan_id', 'pending_effective_at',
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
            'paused_at' => 'datetime',
            'pending_effective_at' => 'datetime',
        ];
    }

    /** Paused: access and metering are suspended until the subscription is resumed. */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** Has a deferred plan change waiting to take effect at the period end. */
    public function hasPendingChange(): bool
    {
        return $this->pending_plan_id !== null;
    }

    /**
     * The subscription's effective standing for presentation: `paused` overrides the
     * engine status, so a paused-but-still-`Active` row reads honestly as paused.
     */
    public function standing(): string
    {
        return $this->isPaused() ? 'paused' : $this->status->value;
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

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** @return HasMany<SubscriptionAddOn, $this> */
    public function addOns(): HasMany
    {
        return $this->hasMany(SubscriptionAddOn::class);
    }
}
