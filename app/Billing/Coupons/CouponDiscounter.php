<?php

declare(strict_types=1);

namespace App\Billing\Coupons;

use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Models\Coupon;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\CouponApplier;
use Cbox\Billing\Pricing\ValueObjects\Coupon as EngineCoupon;
use DateTimeImmutable;

/**
 * The single place a coupon turns a net {@see Money} into a discount, delegating every
 * money reduction to the engine {@see CouponApplier} (percentage → `proratedBy`, fixed →
 * `minus` floored at zero). The discount amount is DERIVED from the engine's own output
 * (`full − discounted`), never a hand-rolled percentage. Applying before tax matches the
 * engine's contract: a coupon reduces the net taxable amount, and the quote builder taxes
 * the reduced net.
 *
 * Both the redemption preview (from a live {@see Coupon}) and every invoice/MRR application
 * (from a durable {@see SubscriptionCoupon} binding) flow through here, so the discounted
 * amount a customer previews is by construction the amount they are charged.
 */
readonly class CouponDiscounter
{
    public function __construct(private CouponApplier $applier) {}

    /**
     * The discount a live coupon would apply to `$net` at `$at`. Returns null when the
     * coupon does not reduce the amount (an out-of-currency fixed coupon, or a zero
     * discount).
     */
    public function forCoupon(Coupon $coupon, Money $net, DateTimeImmutable $at): ?CouponDiscount
    {
        if (! $this->currencyCompatible($coupon->currency, $coupon->discount_type, $net)) {
            return null;
        }

        return $this->compute($coupon->code, $this->couponLabel($coupon), $coupon->toEngineCoupon(), $net, $at);
    }

    /**
     * The discount a subscription's bound coupon applies to `$net` at `$at` — the
     * per-invoice / per-MRR figure. Returns null when the binding no longer applies (its
     * periods are spent) or the currency is incompatible, so the caller adds no discount
     * line and bills full price.
     */
    public function forBinding(SubscriptionCoupon $binding, Money $net, DateTimeImmutable $at): ?CouponDiscount
    {
        if (! $binding->appliesNow()) {
            return null;
        }

        if (! $this->currencyCompatible($binding->currency, $binding->discount_type, $net)) {
            return null;
        }

        return $this->compute($binding->code, $binding->label(), $binding->toEngineCoupon(), $net, $at);
    }

    private function compute(string $code, string $label, EngineCoupon $engine, Money $net, DateTimeImmutable $at): ?CouponDiscount
    {
        $discounted = $this->applier->apply($net, $engine, $at);
        $amount = $net->minus($discounted);

        if (! $amount->isPositive()) {
            return null;
        }

        return new CouponDiscount($code, $label, $net, $discounted, $amount);
    }

    /** A percentage coupon fits any currency; a fixed coupon must match the net's currency. */
    private function currencyCompatible(?string $couponCurrency, string $discountType, Money $net): bool
    {
        if ($discountType !== 'fixed_amount') {
            return true;
        }

        return $couponCurrency !== null && $couponCurrency === $net->currency();
    }

    private function couponLabel(Coupon $coupon): string
    {
        if ($coupon->discountKind()->value === 'percent') {
            return sprintf('%s (%d%% off)', $coupon->code, (int) ($coupon->percent_off ?? 0));
        }

        return sprintf('%s discount', $coupon->code);
    }
}
