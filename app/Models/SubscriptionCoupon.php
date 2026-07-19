<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\Enums\DiscountType;
use Cbox\Billing\Pricing\ValueObjects\Coupon as EngineCoupon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coupon BOUND to a subscription across its billing cycles — the durable state that lets
 * the renewal invoicer honor a coupon's duration (`once` / `repeating` / `forever`). It is
 * a SNAPSHOT of the discount at redemption time, so a later edit or delete of the coupon
 * never silently reprices a live subscriber.
 *
 * `remaining_periods` counts the invoices still owed the discount: `once` opens at 1,
 * `repeating` at N, `forever` is null (unbounded). The invoicer decrements it per issued
 * period invoice; a binding with `remaining_periods = 0` no longer discounts.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $coupon_id
 * @property string $code
 * @property string $discount_type
 * @property int|null $percent_off
 * @property int|null $amount_off_minor
 * @property string|null $currency
 * @property string $duration
 * @property int|null $remaining_periods
 */
class SubscriptionCoupon extends Model
{
    protected $fillable = [
        'subscription_id', 'coupon_id', 'code', 'discount_type', 'percent_off',
        'amount_off_minor', 'currency', 'duration', 'remaining_periods',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off_minor' => 'integer',
            'remaining_periods' => 'integer',
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

    /**
     * The engine coupon this snapshot maps onto — no validity window (the redemption was
     * already gated on the coupon's expiry; a redeemed discount keeps applying after the
     * code itself expires).
     */
    public function toEngineCoupon(): EngineCoupon
    {
        $type = $this->discountKind()->toEngine();

        return new EngineCoupon(
            code: $this->code,
            type: $type,
            percentage: $type === DiscountType::Percentage ? (int) ($this->percent_off ?? 0) : 0,
            amount: $type === DiscountType::Fixed ? $this->fixedAmount() : null,
        );
    }

    /** The fixed amount off as {@see Money}, or null when not a fixed snapshot. */
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

    /**
     * Whether this binding still discounts an invoice: a `forever` binding always does; any
     * other only while it has periods left.
     */
    public function appliesNow(): bool
    {
        if ($this->durationKind() === CouponDuration::Forever) {
            return true;
        }

        return ($this->remaining_periods ?? 0) > 0;
    }

    /** A human label for the discount, e.g. "SAVE20 (20% off)" or "WELCOME (25.00 off)". */
    public function label(): string
    {
        if ($this->discountKind() === CouponDiscountKind::Percent) {
            return sprintf('%s (%d%% off)', $this->code, (int) ($this->percent_off ?? 0));
        }

        return sprintf('%s discount', $this->code);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
