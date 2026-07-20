<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * One plan column of a rendered pricing table: the plan's identity, its marketing emphasis
 * (featured/badge/highlight), the included-allowance bullets shown on the card, and every
 * precomputed {@see PriceOffer} keyed by `[currency][interval]` so the toggles swap the shown
 * price entirely client-side.
 */
readonly class PricingColumn
{
    /**
     * @param  list<string>  $allowances
     * @param  array<string, array<string, PriceOffer>>  $offers  keyed [currency][interval]
     */
    public function __construct(
        public string $planKey,
        public string $name,
        public bool $featured,
        public ?string $badge,
        public ?string $highlight,
        public array $allowances,
        public array $offers,
    ) {}

    /** The offer for a (currency, interval), or null when the column carries none. */
    public function offer(string $currency, string $interval): ?PriceOffer
    {
        return $this->offers[$currency][$interval] ?? null;
    }
}
