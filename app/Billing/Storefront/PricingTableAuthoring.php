<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Billing\Storefront\Exceptions\PricingTableActionDenied;
use App\Billing\Storefront\ValueObjects\ColumnDraft;
use App\Billing\Storefront\ValueObjects\PricingTableDraft;
use App\Models\PricingTable;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / delete an embeddable {@see PricingTable} and sync its plan columns and
 * feature-comparison rows from a {@see PricingTableDraft}. A pricing table is a pure projection
 * over the catalog — it grants nothing and no subscription depends on it — so unlike the catalog
 * resources it is safe to hard-delete outright; taking one offline without deleting is the
 * `active` flag. The one guard is the public `key`, which must stay unique (it addresses the
 * no-auth page).
 *
 * The column/feature sync is a full replace inside a transaction: the sets are small and
 * order-sensitive, so rewriting them is simpler and less error-prone than a positional diff, and
 * the unique `(table, plan)` / `(table, feature)` constraints are honoured by de-duplicating as
 * we go.
 */
readonly class PricingTableAuthoring
{
    public function create(PricingTableDraft $draft): PricingTable
    {
        $this->assertKeyUnique($draft->key, null);

        return DB::transaction(function () use ($draft): PricingTable {
            $table = PricingTable::query()->create($this->attributes($draft));

            $this->syncColumns($table, $draft->columns);
            $this->syncFeatures($table, $draft->featureIds);

            return $table;
        });
    }

    public function update(PricingTable $table, PricingTableDraft $draft): PricingTable
    {
        $this->assertKeyUnique($draft->key, $table->id);

        return DB::transaction(function () use ($table, $draft): PricingTable {
            $table->update($this->attributes($draft));

            $this->syncColumns($table, $draft->columns);
            $this->syncFeatures($table, $draft->featureIds);

            return $table;
        });
    }

    /** Take a table offline (or back online) without deleting it. */
    public function setActive(PricingTable $table, bool $active): void
    {
        $table->forceFill(['active' => $active])->save();
    }

    /** Hard-delete the table; its columns and feature rows cascade away with it. */
    public function delete(PricingTable $table): void
    {
        $table->delete();
    }

    /**
     * @return array{key: string, name: string, seller_entity_id: ?string, currencies: list<string>|null, default_currency: ?string, interval_toggle: bool, cta_label: ?string, cta_url_template: ?string, active: bool}
     */
    private function attributes(PricingTableDraft $draft): array
    {
        return [
            'key' => $draft->key,
            'name' => $draft->name,
            'seller_entity_id' => $draft->sellerEntityId,
            'currencies' => $draft->currencies === [] ? null : $draft->currencies,
            'default_currency' => $draft->defaultCurrency,
            'interval_toggle' => $draft->intervalToggle,
            'cta_label' => $draft->ctaLabel,
            'cta_url_template' => $draft->ctaUrlTemplate,
            'active' => $draft->active,
        ];
    }

    /**
     * @param  list<ColumnDraft>  $columns
     */
    private function syncColumns(PricingTable $table, array $columns): void
    {
        $table->columns()->delete();

        $order = 0;
        $seen = [];

        foreach ($columns as $column) {
            // Honour the unique (table, plan) constraint — a plan named twice keeps its first slot.
            if (in_array($column->planId, $seen, true)) {
                continue;
            }

            $seen[] = $column->planId;

            $table->columns()->create([
                'plan_id' => $column->planId,
                'annual_plan_id' => $column->annualPlanId,
                'sort_order' => $order++,
                'featured' => $column->featured,
                'badge' => $column->badge,
                'highlight' => $column->highlight,
            ]);
        }
    }

    /**
     * @param  list<int>  $featureIds
     */
    private function syncFeatures(PricingTable $table, array $featureIds): void
    {
        $table->featureRows()->delete();

        $order = 0;
        $seen = [];

        foreach ($featureIds as $featureId) {
            if (in_array($featureId, $seen, true)) {
                continue;
            }

            $seen[] = $featureId;

            $table->featureRows()->create([
                'feature_id' => $featureId,
                'sort_order' => $order++,
            ]);
        }
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = PricingTable::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw PricingTableActionDenied::duplicateKey($key);
        }
    }
}
