<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Exceptions;

use RuntimeException;

/**
 * Raised when a coupon CRUD action is refused by a referential-integrity guard — a
 * duplicate code, or a hard-delete of a coupon that has already been redeemed (its
 * redemption ledger and any live subscription bindings must be preserved; a redeemed
 * coupon is archived, never deleted). The console controller catches it and flashes the
 * reason, so the guard is enforced server-side and never relies on the confirm dialog.
 */
class CouponActionDenied extends RuntimeException
{
    public static function duplicateCode(string $code): self
    {
        return new self(sprintf('The code "%s" is already in use. Coupon codes must be unique.', $code));
    }

    public static function redeemed(string $code, int $redemptions): self
    {
        return new self(sprintf(
            'Coupon "%s" has %d redemption%s. Archive it instead — deleting would orphan its redemption history and any live discounts.',
            $code,
            $redemptions,
            $redemptions === 1 ? '' : 's',
        ));
    }
}
