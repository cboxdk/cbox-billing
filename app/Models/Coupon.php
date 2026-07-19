<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Mode\Concerns\BelongsToMode;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\CouponApplier;
use Cbox\Billing\Pricing\Enums\DiscountType;
use Cbox\Billing\Pricing\ValueObjects\Coupon as EngineCoupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An authored discount / promo code. The DISCOUNT MATH is never on this model — it maps to
 * the engine's {@see EngineCoupon} ({@see toEngineCoupon()}) and the engine
 * {@see CouponApplier} does every money reduction. This row models
 * only the app-side lifecycle the engine primitive has no concept of: redemption limits, an
 * expiry, a plan scope, and the DURATION that binds a discount across renewals.
 *
 * A redeemed coupon is archived (`archived_at`), never hard-deleted, so its redemption
 * ledger and any live subscription bindings are preserved.
 *
 * @property int $id
 * @property string $code
 * @property string|null $name
 * @property string $discount_type
 * @property int|null $percent_off
 * @property int|null $amount_off_minor
 * @property string|null $currency
 * @property string $duration
 * @property int|null $duration_in_periods
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property int|null $max_redemptions_per_customer
 * @property Carbon|null $redeem_by
 * @property string $applies_to
 * @property list<string>|null $applies_to_plans
 * @property bool $active
 * @property Carbon|null $archived_at
 */
class Coupon extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'code', 'name', 'discount_type', 'percent_off', 'amount_off_minor', 'currency',
        'duration', 'duration_in_periods', 'max_redemptions', 'times_redeemed',
        'max_redemptions_per_customer', 'redeem_by', 'applies_to', 'applies_to_plans',
        'active', 'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off_minor' => 'integer',
            'duration_in_periods' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'max_redemptions_per_customer' => 'integer',
            'redeem_by' => 'datetime',
            'applies_to_plans' => 'array',
            'active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function discountKind(): CouponDiscountKind
    {
        return CouponDiscountKind::from($this->discount_type);
    }

    public function durationKind(): CouponDuration
    {
        return CouponDuration::from($this->duration);
    }

    public function scope(): CouponScope
    {
        return CouponScope::from($this->applies_to);
    }

    /**
     * The engine coupon this maps onto — the value object {@see CouponApplier}
     * consumes. A fixed discount carries its {@see Money} amount; a percentage carries its
     * integer percentage. `redeem_by` becomes the engine's `validUntil` window.
     */
    public function toEngineCoupon(): EngineCoupon
    {
        $type = $this->discountKind()->toEngine();

        return new EngineCoupon(
            code: $this->code,
            type: $type,
            percentage: $type === DiscountType::Percentage ? (int) ($this->percent_off ?? 0) : 0,
            amount: $type === DiscountType::Fixed ? $this->fixedAmount() : null,
            validUntil: $this->redeem_by?->toDateTimeImmutable(),
        );
    }

    /** The fixed amount off as {@see Money}, or null when this is not a fixed coupon. */
    public function fixedAmount(): ?Money
    {
        if ($this->discountKind() !== CouponDiscountKind::FixedAmount) {
            return null;
        }

        if ($this->amount_off_minor === null || $this->currency === null) {
            return null;
        }

        return Money::ofMinor($this->amount_off_minor, $this->currency);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isExpiredAt(Carbon $at): bool
    {
        return $this->redeem_by !== null && $this->redeem_by->lessThanOrEqualTo($at);
    }

    /** Whether the per-coupon redemption cap is exhausted. */
    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions;
    }

    /**
     * Whether this coupon may apply to the given plan. An `all`-scoped coupon applies to
     * every plan; a `plans`-scoped coupon applies only to a plan key on its allow-list
     * (deny-by-default).
     */
    public function appliesToPlan(Plan $plan): bool
    {
        if ($this->scope() === CouponScope::All) {
            return true;
        }

        return in_array($plan->key, $this->applies_to_plans ?? [], true);
    }

    /**
     * Whether the coupon is in a redeemable STATE at `$at` — active, not archived, not
     * expired, and not exhausted. Per-customer and per-plan checks are the redeemer's, made
     * with the plan/org in hand.
     */
    public function isRedeemableAt(Carbon $at): bool
    {
        return $this->active
            && ! $this->isArchived()
            && ! $this->isExpiredAt($at)
            && ! $this->isExhausted();
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Only live (active, non-archived) coupons.
     *
     * @param  Builder<Coupon>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('active', true)->whereNull('archived_at');
    }
}
