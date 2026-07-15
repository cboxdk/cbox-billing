<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Read model for the Catalog screen. Projects real {@see Product} / {@see Plan} rows with
 * their per-currency {@see PlanPrice}s and metered entitlements — the catalog the engine
 * prices and provisions from — into the shape the catalog table renders.
 */
readonly class CatalogReport
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function products(): Collection
    {
        return Product::query()
            ->with(['plans.prices', 'plans.entitlements.meter', 'plans.creditGrants'])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $this->product($product));
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        return [
            'key' => $product->key,
            'name' => $product->name,
            'description' => $product->description,
            'plans' => $product->plans
                ->sortBy('id')
                ->map(fn (Plan $plan): array => $this->plan($plan))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'active' => $plan->active,
            'prices' => $plan->prices
                ->sortBy('currency')
                ->map(static fn (PlanPrice $price): array => [
                    'currency' => $price->currency,
                    'minor' => $price->price_minor,
                ])->values()->all(),
            'credits' => $plan->creditGrants
                ->map(static fn (PlanCreditGrant $grant): array => [
                    'pool' => $grant->pool,
                    'amount' => $grant->amount,
                    'denomination' => $grant->denomination,
                ])->values()->all(),
            'entitlements' => $plan->entitlements
                ->sortBy('meter_id')
                ->map(static function (PlanEntitlement $entitlement): array {
                    $meter = $entitlement->meter;

                    return [
                        'meter' => $meter !== null ? $meter->name : '—',
                        'unit' => $meter !== null ? $meter->unit : '',
                        'enabled' => $entitlement->enabled,
                        'unlimited' => $entitlement->unlimited,
                        'allowance' => $entitlement->allowance,
                        'overage' => $entitlement->overage->value,
                    ];
                })->values()->all(),
        ];
    }
}
