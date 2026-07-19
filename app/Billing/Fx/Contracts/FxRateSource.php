<?php

declare(strict_types=1);

namespace App\Billing\Fx\Contracts;

use App\Billing\Fx\EcbFxRateSource;
use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\StaticFxRateSource;
use App\Billing\Fx\ValueObjects\FxRate;

/**
 * A source of foreign-exchange reference rates the `fx:refresh` pull ingests into `fx_rates`.
 * Two implementations ship: the ECB euro-reference-rate feed adapter
 * ({@see EcbFxRateSource}) and the operator/treasury override source
 * ({@see StaticFxRateSource}). A source is deny-by-default: it emits only the
 * rates it genuinely has, and NEVER invents one — a pair it cannot supply is simply absent, so
 * the consolidator reports "rate unavailable" honestly rather than fabricating a number.
 *
 * Kept a contract so a deployment can register a third source (an internal treasury feed, a
 * paid provider) by binding it — the refresher and the console read the same interface.
 */
interface FxRateSource
{
    /** This source's provenance tag, stamped on every row it contributes. */
    public function origin(): FxRateOrigin;

    /**
     * Pull the current rate set from the source. May perform IO (an HTTP fetch for ECB) or read
     * config (the override source). Returns an empty list when the source has nothing to offer;
     * it must never fabricate a rate.
     *
     * @return list<FxRate>
     */
    public function rates(): array;
}
