<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the Features screens — the routable feature catalog list and detail page (the
 * boolean/config peer of {@see MeterReport}). Projects real {@see Feature} rows with their type,
 * value type and reference counts (plan grants) into the table and detail shapes. No writes.
 */
readonly class FeatureReport
{
    /**
     * The paginated, optionally searched feature list. Search matches key, name or description.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Feature::query()
            ->withCount('planFeatures')
            ->orderBy('key');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('key', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Feature $feature): array => $this->row($feature))
            ->withQueryString();
    }

    /**
     * The detail shape for one feature: its fields and the plan grants referencing it. Null when
     * the feature does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $feature = Feature::query()
            ->with(['planFeatures.plan.product'])
            ->find($id);

        if (! $feature instanceof Feature) {
            return null;
        }

        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'name' => $feature->name,
            'description' => $feature->description,
            'type' => $feature->type->value,
            'value_type' => $feature->value_type?->value,
            'archived' => $feature->isArchived(),
            'archived_at' => $feature->archived_at?->format('j M Y'),
            'grants' => $feature->planFeatures
                ->map(static function (PlanFeature $grant): array {
                    $plan = $grant->plan;
                    $product = $plan instanceof Plan ? $plan->product : null;

                    return [
                        'plan_id' => $plan instanceof Plan ? $plan->id : null,
                        'plan' => $plan instanceof Plan ? $plan->name : '—',
                        'product' => $product instanceof Product ? $product->name : '—',
                        'enabled' => $grant->enabled,
                        'value' => $grant->value,
                    ];
                })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'name' => $feature->name,
            'type' => $feature->type->value,
            'value_type' => $feature->value_type?->value,
            'archived' => $feature->isArchived(),
            'grants' => $feature->plan_features_count,
        ];
    }
}
