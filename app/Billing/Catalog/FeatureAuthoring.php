<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Features\Enums\ConfigValueType;
use App\Billing\Features\Enums\FeatureType;
use App\Models\Feature;

/**
 * Create / edit / archive / delete a {@see Feature} (the boolean/config peer of {@see
 * MeterAuthoring}). A feature's `type` is boolean (pure on/off) or config (carries a typed
 * value/limit); a boolean feature never carries a `value_type`, and a config feature must. Removal
 * is guarded: a feature a plan grant references is archived (soft-deactivated) so its grants keep
 * resolving — only a never-referenced feature is hard-deleted.
 */
readonly class FeatureAuthoring
{
    /**
     * @param  array{key: string, name: string, description: ?string, type: FeatureType, value_type: ?ConfigValueType}  $data
     */
    public function create(array $data): Feature
    {
        $this->assertKeyUnique($data['key'], null);

        return Feature::query()->create($this->attributes($data));
    }

    /**
     * @param  array{key: string, name: string, description: ?string, type: FeatureType, value_type: ?ConfigValueType}  $data
     */
    public function update(Feature $feature, array $data): Feature
    {
        $this->assertKeyUnique($data['key'], $feature->id);

        $feature->update($this->attributes($data));

        return $feature;
    }

    /** Soft-deactivate the feature; existing plan grants keep resolving. */
    public function archive(Feature $feature): void
    {
        $feature->forceFill(['archived_at' => now()])->save();
    }

    /** Reinstate an archived feature. */
    public function unarchive(Feature $feature): void
    {
        $feature->forceFill(['archived_at' => null])->save();
    }

    /**
     * Hard-delete a feature — refused while a plan grant references it, so no grant is orphaned.
     * Archive instead. (Org overrides cascade with the feature and are org-scoped, so they do not
     * block deletion the way a plan grant does.)
     */
    public function delete(Feature $feature): void
    {
        $grants = $feature->planFeatures()->count();

        if ($grants > 0) {
            throw CatalogActionDenied::featureReferenced($feature->name, $grants);
        }

        $feature->delete();
    }

    /**
     * @param  array{key: string, name: string, description: ?string, type: FeatureType, value_type: ?ConfigValueType}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        // A boolean feature never carries a value type; a config feature always does. Normalize
        // so the stored row is unambiguous regardless of what the form posted.
        $isConfig = $data['type'] === FeatureType::Config;

        return [
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'],
            'type' => $data['type'],
            'value_type' => $isConfig ? ($data['value_type'] ?? ConfigValueType::Integer) : null,
        ];
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = Feature::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw CatalogActionDenied::duplicateKey($key);
        }
    }
}
