<?php

declare(strict_types=1);

namespace App\Billing\Support;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;

/**
 * Parses an operator-entered major-unit amount ("1234.56") into integer minor units for a
 * GIVEN CURRENCY — the write-side counterpart to {@see MoneyFormatter}, which is the read side.
 *
 * The number of minor units in a major unit is the CURRENCY's own exponent, never a hardcoded
 * 100. JPY and KRW carry zero decimals, so ¥1500 is 1500 minor, not 150000; BHD, KWD and TND
 * carry three, so 1.500 BHD is 1500 minor, not 150. A blanket `* 100` overstates a JPY amount
 * by 100× and understates a BHD one by 10× — and because {@see MoneyFormatter} already asks
 * brick/money for the exponent when it RENDERS, a hardcoded parser makes the two halves of the
 * system disagree about what the same stored integer means. On a quote that a customer accepts
 * and an invoice collects, that disagreement is billed.
 *
 * Rounding is HALF_UP rather than truncation: an operator who types a third decimal on a
 * two-decimal currency means the nearest cent, not the one below it.
 *
 * The currency must already be a known ISO 4217 code — validate it at the boundary with
 * {@see Rules\IsoCurrency} so an unknown code is a 422 rather than an exception in here.
 */
class MinorUnits
{
    /**
     * Parse `$value` as a major-unit amount in `$currency`.
     *
     * Non-numeric input yields 0, matching the lenient contract the console's optional amount
     * fields rely on (validation upstream is what rejects a genuinely bad amount).
     *
     * @throws UnknownCurrencyException when `$currency` is not ISO 4217
     */
    public static function parse(mixed $value, string $currency): int
    {
        if (is_string($value)) {
            $string = trim($value);
        } elseif (is_int($value) || is_float($value)) {
            $string = (string) $value;
        } else {
            return 0;
        }

        if ($string === '' || ! is_numeric($string)) {
            return 0;
        }

        try {
            return BrickMoney::of($string, strtoupper($currency), roundingMode: RoundingMode::HalfUp)
                ->getMinorAmount()
                ->toInt();
        } catch (MathException) {
            // An amount too large for the currency's scale to represent as an integer. The
            // boundary validates a max, so this is unreachable input rather than a value we
            // should silently coerce to something billable.
            return 0;
        }
    }
}
