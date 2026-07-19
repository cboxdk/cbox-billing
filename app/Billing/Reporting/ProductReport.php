<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the Products screens — the routable product list and detail page. Projects
 * real {@see Product} rows with their {@see Plan}s, price/entitlement counts and live
 * subscriber totals into the shapes the products table and detail page render. No writes.
 */
readonly class ProductReport
{
    /**
     * The paginated, optionally searched product list. Search matches name, key or
     * description.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Product::query()
            ->withCount('plans')
            ->with('plans:id,product_id,active')
            ->orderBy('name');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('name', 'like', '%'.$search.'%')
                    ->orWhere('key', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Product $product): array => $this->row($product))
            ->withQueryString();
    }

    /**
     * The detail shape for one product: its metadata, plans (with price/subscriber counts),
     * and roll-up stats. Null when the product does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $product = Product::query()
            ->with('plans')
            ->withCount('plans')
            ->find($id);

        if (! $product instanceof Product) {
            return null;
        }

        $orderedPlans = $product->plans->sortBy('id')->values();
        $planIds = $orderedPlans->map(static fn (Plan $plan): int => $plan->id)->all();
        $subscriberCounts = $this->subscriberCounts($planIds);

        $plans = $orderedPlans->map(fn (Plan $plan): array => [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'active' => $plan->active,
            'retiring' => $plan->isRetiring(),
            'prices' => $plan->prices()->count(),
            'entitlements' => $plan->entitlements()->count(),
            'subscribers' => $subscriberCounts[$plan->id] ?? 0,
        ])->values()->all();

        return [
            'id' => $product->id,
            'key' => $product->key,
            'name' => $product->name,
            'description' => $product->description,
            'archived' => $product->isArchived(),
            'archived_at' => $product->archived_at?->format('j M Y'),
            'plans' => $plans,
            'plan_count' => $product->plans_count,
            'active_plans' => count(array_filter($plans, static fn (array $plan): bool => (bool) $plan['active'])),
            'subscribers' => array_sum($subscriberCounts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        $active = $product->plans->filter(static fn (Plan $plan): bool => $plan->active)->count();

        return [
            'id' => $product->id,
            'key' => $product->key,
            'name' => $product->name,
            'description' => $product->description,
            'archived' => $product->isArchived(),
            'plans' => $product->plans_count,
            'active_plans' => $active,
        ];
    }

    /**
     * Serving-subscriber totals keyed by plan id, over the given plans.
     *
     * @param  array<int, int>  $planIds
     * @return array<int, int>
     */
    private function subscriberCounts(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $counts = [];

        foreach (Subscription::query()->serving()->whereIn('plan_id', $planIds)->get(['plan_id']) as $subscription) {
            $counts[$subscription->plan_id] = ($counts[$subscription->plan_id] ?? 0) + 1;
        }

        return $counts;
    }
}
