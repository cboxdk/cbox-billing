<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

/**
 * Read model for the Meters screens — the routable meter list and detail page. Projects
 * real {@see Meter} rows with their aggregation, reference counts (entitlements + recorded
 * usage) into the table and detail shapes. No writes.
 */
readonly class MeterReport
{
    private const USAGE_TABLE = 'billing_usage_events';

    public function __construct(
        private ConnectionInterface $db,
        private SchemaBuilder $schema,
    ) {}

    /**
     * The paginated, optionally searched meter list. Search matches key, name, display or
     * unit.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Meter::query()
            ->withCount('entitlements')
            ->orderBy('key');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('key', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('display', 'like', '%'.$search.'%')
                    ->orWhere('unit', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Meter $meter): array => $this->row($meter))
            ->withQueryString();
    }

    /**
     * The detail shape for one meter: its fields, the plan entitlements referencing it, and
     * whether it has recorded usage. Null when the meter does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $meter = Meter::query()
            ->with(['entitlements.plan.product'])
            ->find($id);

        if (! $meter instanceof Meter) {
            return null;
        }

        return [
            'id' => $meter->id,
            'key' => $meter->key,
            'name' => $meter->name,
            'display' => $meter->display,
            'unit' => $meter->unit,
            'aggregation' => $meter->aggregation->value,
            'archived' => $meter->isArchived(),
            'archived_at' => $meter->archived_at?->format('j M Y'),
            'has_usage' => $this->hasUsage($meter),
            'entitlements' => $meter->entitlements
                ->map(static function (PlanEntitlement $entitlement): array {
                    $plan = $entitlement->plan;
                    $product = $plan instanceof Plan ? $plan->product : null;

                    return [
                        'plan_id' => $plan instanceof Plan ? $plan->id : null,
                        'plan' => $plan instanceof Plan ? $plan->name : '—',
                        'product' => $product instanceof Product ? $product->name : '—',
                        'enabled' => $entitlement->enabled,
                        'unlimited' => $entitlement->unlimited,
                        'allowance' => $entitlement->allowance,
                    ];
                })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Meter $meter): array
    {
        return [
            'id' => $meter->id,
            'key' => $meter->key,
            'name' => $meter->label(),
            'unit' => $meter->unit,
            'aggregation' => $meter->aggregation->value,
            'archived' => $meter->isArchived(),
            'entitlements' => $meter->entitlements_count,
        ];
    }

    private function hasUsage(Meter $meter): bool
    {
        if (! $this->schema->hasTable(self::USAGE_TABLE)) {
            return false;
        }

        return $this->db->table(self::USAGE_TABLE)->where('meter', $meter->key)->exists();
    }
}
