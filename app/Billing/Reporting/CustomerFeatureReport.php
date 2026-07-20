<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Features\Contracts\ResolvesFeatureEntitlements;
use App\Billing\Features\Enums\FeatureSource;
use App\Billing\Features\FeatureEntitlements;
use App\Billing\Features\ValueObjects\ResolvedFeature;
use App\Models\Feature;

/**
 * Read model for the customer detail page's "Feature entitlements" panel: the org's resolved
 * features (granted vs not, with the plan/override source and any config value) plus the catalog
 * of features an operator can override, and the org's active overrides. No writes — resolution is
 * read through the same {@see FeatureEntitlements} the API uses, so the console shows exactly what
 * a client would see.
 */
readonly class CustomerFeatureReport
{
    public function __construct(private ResolvesFeatureEntitlements $features) {}

    /**
     * @return array{resolved: list<array<string, mixed>>, catalog: list<array<string, mixed>>, has_features: bool}
     */
    public function forOrganization(string $organizationId): array
    {
        $catalog = Feature::query()->orderBy('key')->get();
        $resolvedSet = $this->features->forOrganization($organizationId);

        $rows = [];
        $catalogRows = [];

        foreach ($catalog as $feature) {
            if (! $feature->isArchived()) {
                $catalogRows[] = [
                    'id' => $feature->id,
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'type' => $feature->type->value,
                    'carries_value' => $feature->type->carriesValue(),
                ];
            }

            $resolved = $resolvedSet[$feature->key] ?? null;

            if ($feature->isArchived() && $resolved === null) {
                continue;
            }

            $resolved ??= ResolvedFeature::denied($feature->key, $feature->type);

            // The override state is already carried by the resolved source — no second query is
            // needed. `override` covers both a grant-override and a revoke-override, exactly the
            // rows for which the console shows a "clear override" action.
            $overridden = $resolved->source === FeatureSource::Override;

            $rows[] = [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'type' => $feature->type->value,
                'enabled' => $resolved->enabled,
                'value' => $resolved->value,
                'source' => $resolved->source->value,
                'overridden' => $overridden,
                'carries_value' => $feature->type->carriesValue(),
            ];
        }

        return [
            'resolved' => $rows,
            'catalog' => $catalogRows,
            'has_features' => $rows !== [],
        ];
    }

    /** Convenience: whether the override-source case applies (for the console legend). */
    public function isOverride(string $source): bool
    {
        return $source === FeatureSource::Override->value;
    }
}
