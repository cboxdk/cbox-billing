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

    /**
     * Present a {@see Money} grouped for `$locale`. Danish (and the other continental locales)
     * group with a dot and decimal with a comma ("DKK 1.240,00"); English-style locales are
     * the reverse ("DKK 1,240.00"). The integer-minor value is never re-derived as a float for
     * arithmetic — this only formats the presentation string.
     */
    public static function forLocale(Money $money, string $locale): string
    {
        [$decimal, $thousands] = self::separators($locale);

        return $money->currency().' '.number_format($money->minor() / 100, 2, $decimal, $thousands);
    }

    /**
     * The [decimal, thousands] separators for a locale. English-family locales use ".", ","; the
     * Danish/continental default is ",", ".".
     *
     * @return array{0: string, 1: string}
     */
    private static function separators(string $locale): array
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return $language === 'en' ? ['.', ','] : [',', '.'];
    }
}
