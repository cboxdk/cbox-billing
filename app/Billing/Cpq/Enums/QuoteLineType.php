<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Enums;

use App\Models\Plan;

/**
 * What a quote line prices: a catalog {@see Plan} (priced through the engine tier
 * calculator in the quote currency) or a custom one-off (a free-text description at an authored
 * unit amount).
 */
enum QuoteLineType: string
{
    case Plan = 'plan';
    case Custom = 'custom';

    public function isPlan(): bool
    {
        return $this === self::Plan;
    }

    public function isCustom(): bool
    {
        return $this === self::Custom;
    }
}
