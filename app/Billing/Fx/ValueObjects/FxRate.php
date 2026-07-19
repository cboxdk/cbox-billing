<?php

declare(strict_types=1);

namespace App\Billing\Fx\ValueObjects;

use App\Billing\Fx\Contracts\FxRateSource;
use App\Billing\Fx\Enums\FxRateOrigin;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * One directed exchange quote pulled from a rate source: `1 {base} = rate {quote}`, effective
 * on `asOf`, with its provenance. The rate is a {@see BigDecimal} — an exact decimal, never a
 * float — so a source's published precision survives ingestion untouched. This is the typed
 * unit a {@see FxRateSource} emits and the refresher persists into
 * `fx_rates`.
 */
readonly class FxRate
{
    public function __construct(
        public CarbonImmutable $asOf,
        public string $base,
        public string $quote,
        public BigDecimal $rate,
        public FxRateOrigin $origin,
    ) {}

    public static function of(
        CarbonImmutable $asOf,
        string $base,
        string $quote,
        string $rate,
        FxRateOrigin $origin,
    ): self {
        return new self($asOf, strtoupper($base), strtoupper($quote), BigDecimal::of($rate), $origin);
    }
}
