<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * A single plan-column authoring draft — the typed shape the console form hands the authoring
 * service for one column, before it is persisted as a {@see App\Models\PricingTablePlan}. Its
 * display order is its position in the submitted list, not a field here.
 */
readonly class ColumnDraft
{
    public function __construct(
        public int $planId,
        public ?int $annualPlanId,
        public bool $featured,
        public ?string $badge,
        public ?string $highlight,
    ) {}
}
