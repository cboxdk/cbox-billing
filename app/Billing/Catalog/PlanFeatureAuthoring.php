<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Features\Enums\FeatureType;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;

/**
 * Create / edit / delete a plan's {@see PlanFeature} grant — whether the plan grants a boolean/
 * config feature and, for a config feature, the value it grants. The boolean/config peer of {@see
 * PlanEntitlementAuthoring}. Each grant is one `(plan, feature)` row, so at most one per feature
 * per plan. Deleting a grant reverts that feature to deny-by-default for the plan (safe — a plain
 * hard-delete). Aligns the plan's grants with the on-prem license vocabulary ({@see
 * \Cbox\License\Capabilities}) so a hosted subscription and a license speak the same features.
 */
readonly class PlanFeatureAuthoring
{
    /**
     * @param  array{feature_id: int, enabled: bool, value: ?string}  $data
     */
    public function create(Plan $plan, array $data): PlanFeature
    {
        $feature = $this->feature($data['feature_id']);
        $this->assertFeatureFree($plan, $feature->id, null);

        return $plan->features()->create($this->attributes($feature, $data));
    }

    /**
     * @param  array{feature_id: int, enabled: bool, value: ?string}  $data
     */
    public function update(Plan $plan, PlanFeature $grant, array $data): PlanFeature
    {
        $feature = $this->feature($data['feature_id']);
        $this->assertFeatureFree($plan, $feature->id, $grant->id);

        $grant->update($this->attributes($feature, $data));

        return $grant;
    }

    /** Remove the grant — the feature reverts to deny-by-default for the plan. */
    public function delete(PlanFeature $grant): void
    {
        $grant->delete();
    }

    /**
     * @param  array{feature_id: int, enabled: bool, value: ?string}  $data
     * @return array<string, mixed>
     */
    private function attributes(Feature $feature, array $data): array
    {
        // A boolean feature carries no value; a disabled grant carries no value either. Normalize
        // so a stored grant is unambiguous (only an enabled config grant keeps a value).
        $keepsValue = $data['enabled'] && $feature->type === FeatureType::Config;

        return [
            'feature_id' => $feature->id,
            'enabled' => $data['enabled'],
            'value' => $keepsValue && $data['value'] !== null && $data['value'] !== '' ? $data['value'] : null,
        ];
    }

    private function feature(int $featureId): Feature
    {
        $feature = Feature::query()->find($featureId);

        if (! $feature instanceof Feature) {
            throw CatalogActionDenied::unknownFeature($featureId);
        }

        return $feature;
    }

    private function assertFeatureFree(Plan $plan, int $featureId, ?int $ignoreId): void
    {
        $taken = $plan->features()
            ->where('feature_id', $featureId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw new CatalogActionDenied(sprintf(
                '%s already grants this feature. Edit the existing grant instead.',
                $plan->name,
            ));
        }
    }
}
