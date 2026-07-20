<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

use App\Billing\Notifications\Branding\SellerBranding;

/**
 * A fully-resolved pricing table ready to render — the single object the public page, the
 * embeddable frame, and the console live-preview all read. Every currency/interval permutation
 * is already computed into the columns' {@see PriceOffer}s, so the view holds no catalog model
 * and the toggles need no server call. Branding is the resolved {@see SellerBranding} (the
 * seller's identity with app defaults filled in).
 */
readonly class RenderedPricingTable
{
    /**
     * @param  list<string>  $currencies
     * @param  list<string>  $intervals
     * @param  list<PricingColumn>  $columns
     * @param  list<FeatureRow>  $featureRows
     */
    public function __construct(
        public string $key,
        public string $name,
        public SellerBranding $branding,
        public array $currencies,
        public string $defaultCurrency,
        public array $intervals,
        public string $defaultInterval,
        public bool $hasIntervalToggle,
        public string $ctaLabel,
        public array $columns,
        public array $featureRows,
    ) {}

    /** Whether the table has any priced column to show (an empty table renders an honest note). */
    public function hasColumns(): bool
    {
        return $this->columns !== [];
    }
}
