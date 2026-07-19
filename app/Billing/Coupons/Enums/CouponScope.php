<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Enums;

/**
 * What a coupon may be redeemed against: every plan, or only an explicit allow-list of
 * plan keys ({@see Coupon::$appliesToPlans}). Deny-by-default — a `plans`-scoped coupon
 * refuses any plan not on its list.
 */
enum CouponScope: string
{
    case All = 'all';
    case Plans = 'plans';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All plans',
            self::Plans => 'Specific plans',
        };
    }
}
