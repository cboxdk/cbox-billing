<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Enums;

/**
 * A per-line discount kind. `Percent` reduces the line net by a percentage; `Fixed` subtracts a
 * fixed minor amount (floored at zero). Both are applied to the NET before tax, so the engine
 * taxes the reduced amount — the same contract the coupon engine follows.
 */
enum QuoteDiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
