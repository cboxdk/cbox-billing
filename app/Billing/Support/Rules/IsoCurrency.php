<?php

declare(strict_types=1);

namespace App\Billing\Support\Rules;

use App\Billing\Support\MinorUnits;
use App\Billing\Support\MoneyFormatter;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only a currency brick/money actually knows, so an unknown code is refused at the
 * boundary as a 422 instead of surfacing later as an exception.
 *
 * `size:3` alone lets `ZZZ` through, and every downstream money path — {@see MinorUnits},
 * {@see MoneyFormatter}, the engine's `Money` — resolves the currency to
 * read its exponent. A code that passes validation but has no exponent therefore turns into a
 * 500 on the first render or calculation, far from the request that accepted it, and after the
 * row has already been stored.
 */
class IsoCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a three-letter ISO 4217 currency code.');

            return;
        }

        try {
            Currency::of(strtoupper($value));
        } catch (UnknownCurrencyException) {
            $fail('The :attribute must be a known ISO 4217 currency code, e.g. DKK, EUR or JPY.');
        }
    }
}
