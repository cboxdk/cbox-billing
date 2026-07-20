<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use App\Billing\Import\BillingImporter;
use App\Billing\Import\Contracts\SourceAdapter;

/**
 * The whole parsed export in the app's normalized shape — the single hand-off between a
 * {@see SourceAdapter} (which produces it from provider files) and
 * the {@see BillingImporter} (which plans + commits it). Every list is keyed
 * by nothing but its order; lookups by provider id are indexed lazily by the importer.
 *
 * @param  list<NormalizedProduct>  $products
 * @param  list<NormalizedPlan>  $plans
 * @param  list<NormalizedPrice>  $prices
 * @param  list<NormalizedCoupon>  $coupons
 * @param  list<NormalizedCustomer>  $customers
 * @param  list<NormalizedSubscription>  $subscriptions
 * @param  list<NormalizedInvoice>  $invoices
 */
readonly class NormalizedDataset
{
    /**
     * @param  list<NormalizedProduct>  $products
     * @param  list<NormalizedPlan>  $plans
     * @param  list<NormalizedPrice>  $prices
     * @param  list<NormalizedCoupon>  $coupons
     * @param  list<NormalizedCustomer>  $customers
     * @param  list<NormalizedSubscription>  $subscriptions
     * @param  list<NormalizedInvoice>  $invoices
     */
    public function __construct(
        public array $products = [],
        public array $plans = [],
        public array $prices = [],
        public array $coupons = [],
        public array $customers = [],
        public array $subscriptions = [],
        public array $invoices = [],
    ) {}

    /** The total number of source records across every entity — the "is this a large set?" input. */
    public function total(): int
    {
        return count($this->products)
            + count($this->plans)
            + count($this->prices)
            + count($this->coupons)
            + count($this->customers)
            + count($this->subscriptions)
            + count($this->invoices);
    }

    /**
     * The first price for a given source plan id, or null — the amount + currency that plan is
     * offered at (the importer needs a plan's price before it can create a priceable app plan).
     */
    public function priceForPlan(string $planSourceId): ?NormalizedPrice
    {
        foreach ($this->prices as $price) {
            if ($price->planSourceId === $planSourceId) {
                return $price;
            }
        }

        return null;
    }

    /** The plan with a given source id, or null. */
    public function planById(string $sourceId): ?NormalizedPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->sourceId === $sourceId) {
                return $plan;
            }
        }

        return null;
    }
}
