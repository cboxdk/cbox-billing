<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * One plan column's price for a single (currency, interval) combination — the exact figure the
 * table shows when that currency + interval is selected. Every combination is precomputed so
 * the currency/interval toggles swap values entirely client-side (no server round-trip, so the
 * embedded page stays self-contained and CSP-safe).
 *
 * `available` is false when the column has no plan priced for this combination (e.g. a yearly
 * toggle on a column that carries no annual sibling plan, or a currency the plan is not priced
 * in) — deny-by-default, never a fabricated amount.
 */
readonly class PriceOffer
{
    public function __construct(
        public string $currency,
        public string $interval,
        public bool $available,
        public int $minor,
        public string $formatted,
        public string $per,
        public string $ctaUrl,
    ) {}

    /** The unavailable answer for a (currency, interval) this column is not priced for. */
    public static function unavailable(string $currency, string $interval, string $per): self
    {
        return new self($currency, $interval, false, 0, '—', $per, '');
    }
}
