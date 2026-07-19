<?php

declare(strict_types=1);

namespace App\Billing\Fx\ValueObjects;

use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\FxRateRepository;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The exchange rate actually applied for a `from → to` conversion as of a reporting date —
 * the auditable answer the {@see FxRateRepository} resolves. The rate is a
 * {@see BigRational} (an exact fraction, so a cross-rate's division loses nothing) that
 * multiplies a `from` amount to yield the `to` amount.
 *
 * It carries its own provenance so a converted figure is never a black box:
 *  - `asOf`    — the effective date of the underlying rate row(s): the row on/nearest-before
 *    the requested date. For a cross-rate it is the STALEST of the two legs (a derived rate is
 *    only as fresh as its oldest input).
 *  - `origin`  — {@see FxRateOrigin::Ecb} / {@see FxRateOrigin::Override} for a direct row,
 *    {@see FxRateOrigin::Derived} when computed via the EUR pivot or an inverse leg (an
 *    override leg still surfaces as `override` so a treasury rate is visible).
 *  - `derived` — whether it came straight from a stored directed row (`false`) or was computed
 *    (`true`).
 */
readonly class EffectiveRate
{
    public function __construct(
        public string $from,
        public string $to,
        public BigRational $rate,
        public CarbonImmutable $asOf,
        public FxRateOrigin $origin,
        public bool $derived,
    ) {}

    /**
     * The rate as a fixed-scale decimal string for display (e.g. "0.146112"). Rounded
     * half-up to `$scale` places; the underlying {@see BigRational} stays exact for the
     * money conversion itself, so this only ever affects what the console prints.
     *
     * @param  int<0, max>  $scale
     */
    public function decimal(int $scale = 6): string
    {
        return (string) $this->rate->toScale($scale, RoundingMode::HalfUp);
    }
}
