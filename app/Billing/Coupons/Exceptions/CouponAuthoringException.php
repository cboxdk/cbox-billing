<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Exceptions;

use Cbox\Billing\Pricing\ValueObjects\Coupon;
use RuntimeException;

/**
 * Raised when an authored coupon would not be a valid, applicable discount — the
 * validity guard the write service enforces (mirrors the engine
 * {@see Coupon} constructor invariants, plus the
 * app-side duration/limit rules): a percentage outside 1–100, a fixed discount without a
 * currency or amount, a `repeating` duration without a period count, or a `plans` scope
 * with no plans. Operator-facing message.
 */
class CouponAuthoringException extends RuntimeException
{
    public static function percentageOutOfRange(int $percent): self
    {
        return new self(sprintf('A percentage coupon must be between 1 and 100 — %d is out of range.', $percent));
    }

    public static function fixedNeedsAmount(): self
    {
        return new self('A fixed-amount coupon needs an amount off greater than zero.');
    }

    public static function fixedNeedsCurrency(): self
    {
        return new self('A fixed-amount coupon needs a currency for its amount off.');
    }

    public static function repeatingNeedsPeriods(): self
    {
        return new self('A repeating coupon needs the number of periods it discounts (1 or more).');
    }

    public static function scopeNeedsPlans(): self
    {
        return new self('A plan-scoped coupon needs at least one plan it applies to.');
    }

    public static function unknownPlan(string $key): self
    {
        return new self(sprintf('Plan "%s" does not exist and cannot be added to a coupon scope.', $key));
    }

    public static function limitBelowOne(): self
    {
        return new self('A redemption limit, when set, must be at least 1.');
    }
}
