<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Exceptions;

use RuntimeException;

/**
 * Raised when a promo code cannot be redeemed against a subscribe/checkout — deny-by-default:
 * an unknown, inactive, archived, expired, fully-redeemed, per-customer-exhausted, or
 * not-applicable-to-this-plan code. The message is customer-facing (surfaced localized on
 * the hosted page and as a 422 in the API), so it names the reason without leaking whether
 * the code merely does not exist.
 */
class CouponRedemptionDenied extends RuntimeException
{
    public static function unknown(string $code): self
    {
        return new self(sprintf('The promo code "%s" is not valid.', $code));
    }

    public static function inactive(string $code): self
    {
        return new self(sprintf('The promo code "%s" is no longer active.', $code));
    }

    public static function expired(string $code): self
    {
        return new self(sprintf('The promo code "%s" has expired.', $code));
    }

    public static function limitReached(string $code): self
    {
        return new self(sprintf('The promo code "%s" has reached its redemption limit.', $code));
    }

    public static function customerLimitReached(string $code): self
    {
        return new self(sprintf('The promo code "%s" has already been used on this account.', $code));
    }

    public static function notApplicable(string $code): self
    {
        return new self(sprintf('The promo code "%s" does not apply to the selected plan.', $code));
    }

    public static function currencyMismatch(string $code, string $expected, string $actual): self
    {
        return new self(sprintf(
            'The promo code "%s" is in %s and cannot apply to a %s charge.',
            $code,
            $expected,
            $actual,
        ));
    }
}
