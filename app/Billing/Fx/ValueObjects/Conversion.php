<?php

declare(strict_types=1);

namespace App\Billing\Fx\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The result of converting one {@see Money} to a reporting currency: the original (native)
 * amount, the converted amount, and the {@see EffectiveRate} applied — everything a console
 * needs to show a number and its provenance (native → converted at rate, as-of date), so a
 * consolidated figure is never a black box.
 */
readonly class Conversion
{
    public function __construct(
        public Money $native,
        public Money $converted,
        public EffectiveRate $rate,
    ) {}
}
