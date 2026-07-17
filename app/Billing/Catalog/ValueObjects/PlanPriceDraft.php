<?php

declare(strict_types=1);

namespace App\Billing\Catalog\ValueObjects;

use App\Billing\Catalog\PlanPriceAuthoring;
use Cbox\Billing\Catalog\Enums\PricingModel;

/**
 * A validated-shape authoring request for one plan price: the plan + currency it applies
 * to, its {@see PricingModel}, the base/unit amount, an optional package size, and — for a
 * tiered model — the ordered tier rows. The {@see PlanPriceAuthoring}
 * service prices it through the engine before persisting, so a saved price always bills.
 *
 * Each tier row is `{up_to: int|null, unit_minor: int, flat_minor: int|null}` — `up_to`
 * null marks the final unbounded tier, `unit_minor` the per-unit rate, `flat_minor` the
 * per-tier flat amount (the block price for package, the bracket price for stairstep).
 */
readonly class PlanPriceDraft
{
    /**
     * @param  list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>  $tiers
     */
    public function __construct(
        public int $planId,
        public string $currency,
        public PricingModel $model,
        public int $priceMinor,
        public ?int $packageSize,
        public array $tiers,
    ) {}
}
