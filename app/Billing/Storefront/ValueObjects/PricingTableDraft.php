<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * The typed authoring draft for a whole pricing table — what the console form validates into and
 * the authoring service persists from. The nested {@see ColumnDraft}s and the ordered feature
 * ids carry the columns and the comparison-matrix rows; their order is their list position.
 */
readonly class PricingTableDraft
{
    /**
     * @param  list<string>  $currencies
     * @param  list<ColumnDraft>  $columns
     * @param  list<int>  $featureIds
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $sellerEntityId,
        public array $currencies,
        public ?string $defaultCurrency,
        public bool $intervalToggle,
        public ?string $ctaLabel,
        public ?string $ctaUrlTemplate,
        public bool $active,
        public array $columns,
        public array $featureIds,
    ) {}
}
