<?php

declare(strict_types=1);

namespace App\Webhooks\Events;

use App\Billing\Coupons\CouponRedeemer;
use App\Models\Coupon;
use App\Models\Subscription;

/**
 * A coupon was redeemed against a subscription. Raised by
 * {@see CouponRedeemer::redeem()} once the redemption is recorded and the
 * coupon bound to the subscription, to feed `coupon.redeemed`.
 */
readonly class CouponRedeemed
{
    public function __construct(
        public Coupon $coupon,
        public Subscription $subscription,
    ) {}
}
