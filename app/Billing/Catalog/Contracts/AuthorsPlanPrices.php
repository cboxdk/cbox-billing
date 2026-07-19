<?php

declare(strict_types=1);

namespace App\Billing\Catalog\Contracts;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\Exceptions\CatalogAuthoringException;
use App\Billing\Catalog\ValueObjects\PlanPriceDraft;
use App\Models\PlanPrice;

/**
 * Authors a plan price (create or edit) from a {@see PlanPriceDraft}: validates the tier
 * set against the engine's pricing rules and persists the {@see PlanPrice} plus its tier
 * rows. Deny-by-default — a draft the engine would not price raises
 * {@see CatalogAuthoringException} and nothing is written.
 */
interface AuthorsPlanPrices
{
    /**
     * Persist the draft as the plan's price in its currency (upserting the per-currency
     * row and replacing its tier set), returning the saved {@see PlanPrice}.
     *
     * @throws CatalogAuthoringException when the plan is unknown or the tier set is malformed
     */
    public function save(PlanPriceDraft $draft): PlanPrice;

    /**
     * Remove a price version and its tier set. Guarded by the currency-lock invariant: a
     * price a serving subscriber on a live plan is grandfathered onto (their org's billing
     * currency) cannot be pulled out from under them.
     *
     * @throws CatalogActionDenied when a serving subscriber still bills on this price
     */
    public function delete(PlanPrice $price): void;
}
