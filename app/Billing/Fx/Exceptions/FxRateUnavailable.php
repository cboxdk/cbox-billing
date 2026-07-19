<?php

declare(strict_types=1);

namespace App\Billing\Fx\Exceptions;

use RuntimeException;

/**
 * Thrown when no rate — direct, inverse, or EUR-pivot cross-rate — can be resolved for a
 * `from → to` pair as of a reporting date, and the caller asked for a hard conversion. This is
 * the honest failure mode the FX-data discipline requires: never a fabricated rate. Consolidated
 * reporting catches it and surfaces the currency as "rate unavailable" instead of dropping it
 * silently or inventing a number.
 */
class FxRateUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $asOf,
    ) {
        parent::__construct(sprintf(
            'No FX rate available to convert %s → %s as of %s (no direct, inverse or EUR-pivot rate on/before that date).',
            $from,
            $to,
            $asOf,
        ));
    }
}
