<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PricingTable;
use App\Models\SellerEntity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the pricing-tables console: the paginated list of tables and the option sets a
 * table's authoring form needs (the active plans it can add as columns, the feature catalog it can
 * compare, and the selling entities it can brand with). Reads only — all writes go through
 * {@see PricingTableAuthoring}.
 */
readonly class PricingTableReport
{
    /**
     * @return LengthAwarePaginator<int, PricingTable>
     */
    public function paginate(?string $search): LengthAwarePaginator
    {
        return PricingTable::query()
            ->withCount(['columns', 'featureRows'])
            ->when($search !== null, function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('key', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * The choices the authoring form offers: active plans (as candidate columns / annual
     * siblings), the live feature catalog (as candidate comparison rows), the selling entities
     * (as branding sources), and the union of currencies the plans are priced in.
     *
     * @return array{
     *     plans: list<array{id: int, key: string, name: string, interval: string, currencies: list<string>}>,
     *     features: list<array{id: int, key: string, name: string, type: string}>,
     *     sellers: list<array{id: string, name: string}>,
     *     currencies: list<string>
     * }
     */
    public function formOptions(): array
    {
        $plans = Plan::query()
            ->with('prices')
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (Plan $plan): array => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'interval' => $plan->interval,
                'currencies' => array_values(array_unique($plan->pricedCurrencies())),
            ])
            ->all();

        $features = Feature::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(static fn (Feature $feature): array => [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'type' => $feature->type->value,
            ])
            ->all();

        $sellers = SellerEntity::query()
            ->whereNull('archived_at')
            ->orderBy('legal_name')
            ->get(['id', 'legal_name'])
            ->map(static fn (SellerEntity $seller): array => [
                'id' => $seller->id,
                'name' => $seller->legal_name,
            ])
            ->all();

        $currencies = [];

        foreach ($plans as $plan) {
            foreach ($plan['currencies'] as $currency) {
                $currencies[$currency] = true;
            }
        }

        $currencyList = array_keys($currencies);
        sort($currencyList);

        return [
            'plans' => array_values($plans),
            'features' => array_values($features),
            'sellers' => array_values($sellers),
            'currencies' => $currencyList,
        ];
    }
}
