<?php

declare(strict_types=1);

namespace App\Billing\Coupons\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\CouponApplier;

/**
 * The consequence of applying a coupon to a net amount, computed by the engine
 * {@see CouponApplier} — never hand-rolled arithmetic. Carries the
 * original net, the discounted net, and the discount itself (`full − discounted`, derived
 * from the engine's own outputs), plus the code/label for surfacing on a preview or a
 * discount line.
 */
readonly class CouponDiscount
{
    public function __construct(
        public string $code,
        public string $label,
        public Money $full,
        public Money $discounted,
        public Money $amount,
    ) {}

    /** Whether the coupon actually reduced the amount (a zero discount is a no-op display). */
    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }
}
