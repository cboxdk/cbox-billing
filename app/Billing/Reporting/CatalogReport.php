<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\Product;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
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
            ->with(['plans.prices.tiers', 'plans.entitlements.meter', 'plans.creditGrants'])
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
                ->map(fn (PlanPrice $price): array => $this->price($price))->values()->all(),
            'pricing_model' => $this->planModel($plan),
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

    /**
     * One price projected for display: its currency, base amount, pricing model, and — for
     * a tiered model — the engine {@see PriceTier} set
     * (up-to / unit / flat) plus the package size.
     *
     * @return array{currency: string, minor: int, model: string, tiered: bool, package_size: int|null, tiers: list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>}
     */
    private function price(PlanPrice $price): array
    {
        $model = $price->model();

        return [
            'currency' => $price->currency,
            'minor' => $price->price_minor,
            'model' => $model->value,
            'tiered' => $model->isTiered(),
            'package_size' => $price->package_size,
            'tiers' => array_values($price->tiers
                ->sortBy('sort_order')
                ->map(static fn (PlanPriceTier $tier): array => [
                    'up_to' => $tier->up_to,
                    'unit_minor' => $tier->unit_minor,
                    'flat_minor' => $tier->flat_minor,
                ])->all()),
        ];
    }

    /**
     * The plan's headline pricing model — the model its prices share, or `mixed` when its
     * per-currency rows disagree (they normally do not). `flat` when the plan has no price.
     */
    private function planModel(Plan $plan): string
    {
        $models = $plan->prices
            ->map(static fn (PlanPrice $price): string => $price->model()->value)
            ->unique()
            ->values();

        return match ($models->count()) {
            0 => PricingModel::Flat->value,
            1 => (string) $models->first(),
            default => 'mixed',
        };
    }
}
