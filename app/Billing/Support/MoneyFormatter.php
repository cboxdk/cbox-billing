<?php

declare(strict_types=1);

namespace App\Billing\Support;

use Cbox\Billing\Money\Money;

/**
 * Presents an engine {@see Money} as a Danish-grouped amount (e.g. "DKK 1.240,00").
 * The single money-formatting seam the views call — the amount itself is always the
 * engine's integer-minor value object, never a re-derived float.
 */
class MoneyFormatter
{
    public static function money(Money $money): string
    {
        return $money->currency().' '.number_format($money->minor() / 100, 2, ',', '.');
    }

    public static function minor(int $minor, string $currency): string
    {
        return self::money(Money::ofMinor($minor, $currency));
    }
}
