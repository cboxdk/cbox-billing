<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\Exceptions\ExperimentActionDenied;
use App\Billing\Experiments\ValueObjects\ExperimentDraft;
use App\Billing\Experiments\ValueObjects\VariantDraft;
use App\Models\Experiment;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / delete an {@see Experiment} and sync its variants from an {@see ExperimentDraft}.
 *
 * Validation is the same on create and update, and is where the "a control is required + weights
 * sum sensibly" rule lives (deny-by-default): the draft must carry exactly one control, at least
 * one challenger, non-negative integer weights, and a total weight above zero — otherwise the
 * assignment function would have nothing to bucket into.
 *
 * The variant sync is a full replace inside a transaction: the sets are small and order-sensitive,
 * so rewriting them is simpler and less error-prone than a positional diff. A draft experiment can
 * be freely re-authored; a running/concluded one keeps its definition editable only through the
 * lifecycle service, so authoring here is intended for the draft stage. The public `key` must stay
 * unique (it identifies the experiment) — the one hard guard.
 */
readonly class ExperimentAuthoring
{
    public function create(ExperimentDraft $draft): Experiment
    {
        $this->assertValid($draft);
        $this->assertKeyUnique($draft->key, null);

        return DB::transaction(function () use ($draft): Experiment {
            $experiment = Experiment::query()->create([
                'key' => $draft->key,
                'name' => $draft->name,
                'hypothesis' => $draft->hypothesis,
                'status' => ExperimentStatus::Draft->value,
                'primary_metric' => $draft->primaryMetric->value,
                'pricing_table_id' => $draft->pricingTableId,
            ]);

            $this->syncVariants($experiment, $draft->variants);

            return $experiment;
        });
    }

    public function update(Experiment $experiment, ExperimentDraft $draft): Experiment
    {
        $this->assertValid($draft);
        $this->assertKeyUnique($draft->key, $experiment->id);

        return DB::transaction(function () use ($experiment, $draft): Experiment {
            $experiment->update([
                'key' => $draft->key,
                'name' => $draft->name,
                'hypothesis' => $draft->hypothesis,
                'primary_metric' => $draft->primaryMetric->value,
                'pricing_table_id' => $draft->pricingTableId,
            ]);

            // Re-pointing the winner slot away from a removed variant is handled by the FK
            // (nullOnDelete); clear it here too so a re-authored draft starts clean.
            $experiment->forceFill(['promoted_variant_id' => null])->save();
            $this->syncVariants($experiment, $draft->variants);

            return $experiment;
        });
    }

    /** Hard-delete the experiment; its variants, impressions and conversions cascade away. */
    public function delete(Experiment $experiment): void
    {
        // Break the winner self-reference first so the variants can cascade cleanly.
        $experiment->forceFill(['promoted_variant_id' => null])->save();
        $experiment->delete();
    }

    /**
     * A control is required and the weights must sum sensibly, else the assignment has nothing to
     * bucket into.
     */
    private function assertValid(ExperimentDraft $draft): void
    {
        $controls = 0;
        $challengers = 0;
        $totalWeight = 0;

        foreach ($draft->variants as $variant) {
            if ($variant->isControl) {
                $controls++;
            } else {
                $challengers++;
            }

            $totalWeight += max(0, $variant->weight);
        }

        if ($controls !== 1) {
            throw ExperimentActionDenied::needsControl();
        }

        if ($challengers < 1) {
            throw ExperimentActionDenied::needsVariants();
        }

        if ($totalWeight <= 0) {
            throw ExperimentActionDenied::zeroWeight();
        }
    }

    /**
     * @param  list<VariantDraft>  $variants
     */
    private function syncVariants(Experiment $experiment, array $variants): void
    {
        $experiment->variants()->delete();

        $order = 0;

        foreach ($variants as $variant) {
            $experiment->variants()->create([
                'label' => $variant->label,
                'is_control' => $variant->isControl,
                'weight' => max(0, $variant->weight),
                'sort_order' => $order++,
                'served_pricing_table_id' => $variant->servedPricingTableId,
            ]);
        }
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = Experiment::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ExperimentActionDenied::duplicateKey($key);
        }
    }
}
