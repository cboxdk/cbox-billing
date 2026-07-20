<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $paused_at
 * @property int|null $pending_plan_id
 * @property Carbon|null $pending_effective_at
 * @property string|null $display_standing
 * @property int|null $test_clock_id
 * @property string $environment
 * @property bool $livemode
 */
class Subscription extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seats',
        'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'trial_ends_at', 'canceled_at',
        'paused_at', 'pending_plan_id', 'pending_effective_at',
        'display_standing', 'test_clock_id',
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
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'paused_at' => 'datetime',
            'pending_effective_at' => 'datetime',
        ];
    }

    /**
     * The lifecycle statuses in which a subscription is serving its plan — the engine's
     * {@see SubscriptionStatus::isServing()} set (Trialing, Active, PastDue, NonRenewing),
     * derived from the enum so the app can never drift from the engine's definition. This
     * is the single source of truth for "which statuses are entitled/counted as serving".
     *
     * @return list<string>
     */
    public static function servingStatuses(): array
    {
        return array_values(array_map(
            static fn (SubscriptionStatus $status): string => $status->value,
            array_filter(
                SubscriptionStatus::cases(),
                static fn (SubscriptionStatus $status): bool => $status->isServing(),
            ),
        ));
    }

    /**
     * Constrain a query to subscriptions that are serving their plan: a serving engine
     * status AND no app-layer pause in effect (a paused row is suspended even while its
     * stored status is still Active). The scope every entitlement/serving decision uses so
     * trialing, past-due and non-renewing customers keep their grants while paused and
     * canceled ones do not.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeServing(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::servingStatuses())
            ->whereNull('paused_at');
    }

    /**
     * Whether this subscription is currently serving its plan — a serving engine status AND
     * no app-layer pause in effect. The instance counterpart to {@see scopeServing()}: the
     * seat authority (buy/release/assign) acts only on a serving subscription.
     */
    public function isServing(): bool
    {
        return in_array($this->status->value, self::servingStatuses(), true) && ! $this->isPaused();
    }

    /** In a trial: serving the plan but not yet charged; converts at {@see $trial_ends_at}. */
    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    /** A payment failed and the smart-retry (dunning) schedule is chasing the charge. */
    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /** Terminally canceled — the org is on no plan. */
    public function isCanceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled;
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

    /** Whether this subscription's billing runs on a virtual test clock (sandbox only). */
    public function isTestClockBound(): bool
    {
        return $this->test_clock_id !== null;
    }

    /** @return BelongsTo<TestClock, $this> */
    public function testClock(): BelongsTo
    {
        return $this->belongsTo(TestClock::class);
    }

    /**
     * The coupon bound to this subscription across its cycles (ADR: coupons), or none.
     * A snapshot of the redeemed discount + a remaining-periods counter the renewal
     * invoicer honors.
     *
     * @return HasOne<SubscriptionCoupon, $this>
     */
    public function coupon(): HasOne
    {
        return $this->hasOne(SubscriptionCoupon::class);
    }
}
