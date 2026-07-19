<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Enums;

use Cbox\Billing\Pricing\Enums\DiscountType;

/**
 * How a coupon reduces an amount, in the app's own vocabulary: a percentage of the net,
 * or a fixed money amount off. Maps 1:1 onto the engine's {@see DiscountType} — the app
 * enum exists so the console/schema speak `percent` / `fixed_amount` while the money math
 * stays the engine's.
 */
enum CouponDiscountKind: string
{
    case Percent = 'percent';
    case FixedAmount = 'fixed_amount';

    /** The engine discount type this maps onto. */
    public function toEngine(): DiscountType
    {
        return match ($this) {
            self::Percent => DiscountType::Percentage,
            self::FixedAmount => DiscountType::Fixed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::FixedAmount => 'Fixed amount off',
        };
    }
}
