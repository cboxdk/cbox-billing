<?php

declare(strict_types=1);

namespace App\Billing\Features;

use App\Billing\Features\Contracts\ResolvesFeatureEntitlements;
use App\Billing\Features\ValueObjects\ResolvedFeature;

/**
 * Projects an org's resolved feature set into the flat array shape the `/entitlements/{org}/
 * features` payload serializes — the boolean/config sibling of {@see
 * \App\Billing\Metering\EntitlementsView}. Reads through the same {@see FeatureEntitlements}
 * resolver a single check uses, so the set and a per-key check always agree. Deny-by-default: a
 * feature nobody grants reports `enabled: false`, never omitted.
 */
readonly class FeatureEntitlementsView
{
    public function __construct(private ResolvesFeatureEntitlements $features) {}

    /**
     * @return array<string, array{type: string|null, enabled: bool, value: int|string|null, source: string}>
     */
    public function forOrganization(string $org): array
    {
        return array_map(
            static fn (ResolvedFeature $feature): array => $feature->toArray(),
            $this->features->forOrganization($org),
        );
    }
}
