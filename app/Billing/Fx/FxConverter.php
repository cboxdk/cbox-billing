<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Exceptions\FxRateUnavailable;
use App\Billing\Fx\ValueObjects\Conversion;
use App\Billing\Fx\ValueObjects\EffectiveRate;
use Brick\Math\RoundingMode;
use Brick\Money\Context\DefaultContext;
use Carbon\CarbonInterface;
use Cbox\Billing\Money\Money;

/**
 * Converts an engine {@see Money} to a reporting currency at the rate effective as of a
 * reporting date. Conversion is a REPORTING concern only — the ledger always stays in the
 * transaction's own currency; this never rewrites a stored amount.
 *
 * Rounding + as-of policy (documented, reproducible):
 *  - The rate is resolved by {@see FxRateRepository::effectiveRate()} using the on/nearest-before
 *    date and the EUR-pivot cross-rate rules, as an EXACT fraction.
 *  - The amount is multiplied by that exact fraction and rounded ONCE, half-up, to the target
 *    currency's minor unit (brick/money's {@see DefaultContext}). No intermediate float or
 *    early rounding, so the result is a pure function of (amount, pair, date).
 *  - A same-currency conversion is the identity (rate 1).
 *
 * Two entry points: {@see convert()} throws {@see FxRateUnavailable} when no rate exists (the
 * honest failure — never a fabricated number), and {@see tryConvert()} returns null instead, for
 * callers (consolidated reporting) that surface "rate unavailable" as a first-class outcome.
 */
readonly class FxConverter
{
    public function __construct(private FxRateRepository $rates) {}

    /**
     * Convert `$amount` to `$toCurrency` as of `$asOf`, or throw when no rate is available.
     */
    public function convert(Money $amount, string $toCurrency, CarbonInterface $asOf): Conversion
    {
        $conversion = $this->tryConvert($amount, $toCurrency, $asOf);

        if ($conversion === null) {
            throw new FxRateUnavailable($amount->currency(), strtoupper($toCurrency), $asOf->toDateString());
        }

        return $conversion;
    }

    /**
     * Convert `$amount` to `$toCurrency` as of `$asOf`, or null when no rate is available.
     */
    public function tryConvert(Money $amount, string $toCurrency, CarbonInterface $asOf): ?Conversion
    {
        $toCurrency = strtoupper($toCurrency);
        $rate = $this->rates->effectiveRate($amount->currency(), $toCurrency, $asOf);

        if ($rate === null) {
            return null;
        }

        return new Conversion($amount, $this->apply($amount, $toCurrency, $rate), $rate);
    }

    /**
     * Apply an already-resolved rate — used by the consolidator, which resolves one rate per
     * currency and then converts several amounts (per-currency aggregate, each waterfall
     * component) at the SAME rate, keeping every line reconcilable.
     */
    public function applyRate(Money $amount, EffectiveRate $rate): Money
    {
        return $this->apply($amount, $rate->to, $rate);
    }

    private function apply(Money $amount, string $toCurrency, EffectiveRate $rate): Money
    {
        $converted = $amount->toBrick()->convertedTo(
            $toCurrency,
            $rate->rate,
            new DefaultContext,
            RoundingMode::HalfUp,
        );

        return Money::fromBrick($converted);
    }
}
