<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Contracts;

use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Models\Coupon;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;

/**
 * Turns a net {@see Money} into a coupon discount — the money-path seam every invoice, MRR and
 * redemption-preview caller depends on rather than the concrete applier. The discount is always
 * derived from the engine's own reduction, never a hand-rolled percentage.
 */
interface DiscountsAmounts
{
    /**
     * The discount a live coupon would apply to `$net` at `$at`, or null when it does not reduce
     * the amount (an out-of-currency fixed coupon, or a zero discount).
     */
    public function forCoupon(Coupon $coupon, Money $net, DateTimeImmutable $at): ?CouponDiscount;

    /**
     * The discount a subscription's bound coupon applies to `$net` at `$at` — the per-invoice /
     * per-MRR figure. Null when the binding no longer applies or the currency is incompatible.
     */
    public function forBinding(SubscriptionCoupon $binding, Money $net, DateTimeImmutable $at): ?CouponDiscount;
}
