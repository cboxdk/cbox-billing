<?php

declare(strict_types=1);

namespace App\Billing\Coupons\Enums;

/**
 * How long a redeemed coupon keeps discounting a subscription:
 *
 *  - `once`      — the first invoice only; every renewal is full price.
 *  - `repeating` — the next N period invoices ({@see Coupon::$durationInPeriods}), then stops.
 *  - `forever`   — every renewal, indefinitely (and so reduces reported MRR).
 *
 * The binding's `remaining_periods` counter encodes this: `once` opens at 1, `repeating`
 * at N, `forever` is null (unbounded). The renewal invoicer decrements it per issued
 * invoice.
 */
enum CouponDuration: string
{
    case Once = 'once';
    case Repeating = 'repeating';
    case Forever = 'forever';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Once',
            self::Repeating => 'Repeating',
            self::Forever => 'Forever',
        };
    }

    /**
     * The opening `remaining_periods` for a fresh binding of this duration: 1 for `once`,
     * N for `repeating`, null (unbounded) for `forever`.
     */
    public function openingRemaining(?int $periods): ?int
    {
        return match ($this) {
            self::Once => 1,
            self::Repeating => max(1, $periods ?? 1),
            self::Forever => null,
        };
    }

    /** Whether this duration affects the recurring (MRR-contributing) amount. */
    public function isRecurring(): bool
    {
        return $this !== self::Once;
    }
}
