<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Models\Experiment;
use App\Models\PricingTable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the experiments console: the paginated list of experiments (with their variant
 * count + live impression/conversion totals) and the option sets the authoring form needs (the
 * pricing tables an experiment can run on and the tables its variants can serve). Reads only —
 * all writes go through {@see ExperimentAuthoring} / {@see ExperimentLifecycle}.
 */
readonly class ExperimentReport
{
    /**
     * @return LengthAwarePaginator<int, Experiment>
     */
    public function paginate(?string $search): LengthAwarePaginator
    {
        return Experiment::query()
            ->with('pricingTable')
            ->withCount(['variants', 'impressions', 'conversions'])
            ->when($search !== null, function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('key', 'like', '%'.$search.'%');
                });
            })
            ->orderByRaw("case status when 'running' then 0 when 'draft' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * The choices the authoring form offers: the pricing tables (both as the base the experiment
     * runs on and as the tables its variants can serve).
     *
     * @return array{tables: list<array{id: int, key: string, name: string, active: bool}>}
     */
    public function formOptions(): array
    {
        $tables = PricingTable::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (PricingTable $table): array => [
                'id' => $table->id,
                'key' => $table->key,
                'name' => $table->name,
                'active' => $table->active,
            ])
            ->all();

        return ['tables' => array_values($tables)];
    }
}
