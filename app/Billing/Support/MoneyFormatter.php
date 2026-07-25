<?php

declare(strict_types=1);

namespace App\Billing\Support;

use Cbox\Billing\Money\Money;

/**
 * Presents an engine {@see Money} as a grouped amount (e.g. "DKK 1.240,00", "JPY 15.000",
 * "BHD 1,500"). The single money-formatting seam the views, PDFs and mail templates call.
 *
 * The number of decimals is the CURRENCY's own exponent, never a hardcoded 2, and the digits
 * come from the value object's exact decimal amount rather than a `minor / 100` float. That
 * matters because the platform is not DKK-only: JPY and KRW carry zero decimals, and BHD, KWD
 * and TND carry three. Dividing minor units by 100 renders ¥15.000 as "JPY 150,00" — a 100×
 * understatement on an invoice PDF, which is a legal document. brick/money already knows every
 * currency's exponent; this seam asks it rather than assuming.
 */
class MoneyFormatter
{
    /** Present in the Danish/continental default grouping ("DKK 1.240,00"). */
    public static function money(Money $money): string
    {
        return self::forLocale($money, 'da');
    }

    public static function minor(int $minor, string $currency): string
    {
        return self::money(Money::ofMinor($minor, $currency));
    }

    /**
     * Present a {@see Money} grouped for `$locale`. Danish (and the other continental locales)
     * group with a dot and decimal with a comma ("DKK 1.240,00"); English-style locales are
     * the reverse ("DKK 1,240.00"). The integer-minor value is never re-derived as a float —
     * this only formats the presentation string.
     */
    public static function forLocale(Money $money, string $locale): string
    {
        [$decimal, $thousands] = self::separators($locale);

        // The value object's exact decimal amount, already scaled to the currency's exponent
        // (brick/money sets the scale from the ISO 4217 minor unit): "1240.00", "15000",
        // "1.500". Splitting the string keeps the value exact — no float ever appears.
        $amount = (string) $money->toBrick()->getAmount();
        $negative = str_starts_with($amount, '-');

        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');

        $formatted = number_format((int) $whole, 0, $decimal, $thousands);

        if ($fraction !== '') {
            $formatted .= $decimal.$fraction;
        }

        return $money->currency().' '.($negative ? '-' : '').$formatted;
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
