<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated\ValueObjects;

use App\Billing\Fx\ValueObjects\EffectiveRate;
use Cbox\Billing\Reporting\ValueObjects\MrrWaterfall;

/**
 * One currency's native MRR-movement bridge and the rate used to fold it into the consolidated
 * (reporting-currency) bridge. `rate` is null and {@see available()} false when that currency
 * had no resolvable period-end rate — its movement is then excluded from the consolidation and
 * surfaced honestly rather than converted at a made-up rate.
 */
readonly class CurrencyMovementLine
{
    public function __construct(
        public MrrWaterfall $native,
        public ?EffectiveRate $rate,
    ) {}

    public function available(): bool
    {
        return $this->rate !== null;
    }
}
