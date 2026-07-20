<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Contracts;

use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Validates, previews and redeems coupons — the money-path seam the checkout, subscribe, portal
 * and import flows depend on rather than the concrete redeemer. Validation throws a
 * `CouponRedemptionDenied` on any failed rule; redemption is atomic under a row lock so the
 * redemption caps hold under concurrency.
 */
interface RedeemsCoupons
{
    /**
     * Validate `$code` for `$plan`/`$currency`/`$organizationId` at `$at` (default now), returning
     * the applicable {@see Coupon} or throwing `CouponRedemptionDenied`.
     */
    public function validate(string $code, Plan $plan, string $currency, string $organizationId, ?Carbon $at = null): Coupon;

    /**
     * The discount this coupon would apply to `$net` — the figure checkout/subscribe previews and
     * is charged. Null when the coupon does not reduce it.
     */
    public function discountFor(Coupon $coupon, Money $net, ?Carbon $at = null): ?CouponDiscount;

    /**
     * Record a redemption of `$coupon` by `$subscription`'s org and bind it to the subscription,
     * atomically under a lock so the redemption caps hold. Returns the durable binding.
     */
    public function redeem(Coupon $coupon, Subscription $subscription, ?Carbon $at = null): SubscriptionCoupon;

    /** Whether the coupon is scoped to specific plans (vs applying account-wide). */
    public function isPlanScoped(Coupon $coupon): bool;
}
