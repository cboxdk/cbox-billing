<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated\ValueObjects;

use App\Billing\Fx\ValueObjects\EffectiveRate;
use Cbox\Billing\Money\Money;

/**
 * One currency's contribution to consolidated MRR: the native amount actually billed in that
 * currency, how many subscriptions stand behind it, and — when a rate exists — the amount
 * converted to the reporting currency plus the exact {@see EffectiveRate} applied (its decimal
 * value, provenance and as-of date). When no rate can be resolved, `converted` and `rate` are
 * null and {@see available()} is false: the line is shown as "rate unavailable", never dropped
 * or fabricated.
 */
readonly class CurrencyMrrLine
{
    public function __construct(
        public string $currency,
        public Money $native,
        public int $subscriptions,
        public ?Money $converted,
        public ?EffectiveRate $rate,
    ) {}

    /** Whether this native currency could be converted to the reporting currency. */
    public function available(): bool
    {
        return $this->converted !== null;
    }
}
