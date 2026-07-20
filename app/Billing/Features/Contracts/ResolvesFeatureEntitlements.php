<?php

declare(strict_types=1);

namespace App\Billing\Features\Contracts;

use App\Billing\Features\ValueObjects\ResolvedFeature;

/**
 * Resolves an org's boolean / config feature entitlements from its serving plan's grants plus any
 * org override, deny-by-default. The `/features` API, the console and the upgrade gate depend on
 * this contract rather than the concrete resolver, so a host can override resolution and the
 * caching implementation stays swappable.
 */
interface ResolvesFeatureEntitlements
{
    /**
     * The org's full resolved feature set, keyed by feature key.
     *
     * @return array<string, ResolvedFeature>
     */
    public function forOrganization(string $org): array;

    /** The resolved answer for a single feature key (unknown key → deny-by-default, never a 404). */
    public function resolve(string $org, string $key): ResolvedFeature;

    /** Whether the org has the feature granted (the boolean the upgrade gate gates on). */
    public function has(string $org, string $key): bool;

    /** Drop the cached per-org context (after a grant/override/subscription change). */
    public function flush(): void;
}
