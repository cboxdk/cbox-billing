<?php

declare(strict_types=1);

namespace App\Billing\Features;

use App\Billing\Features\Enums\FeatureSource;
use App\Billing\Features\ValueObjects\ResolvedFeature;
use App\Billing\Metering\EntitlementsView;
use App\Models\Feature;
use App\Models\OrganizationFeatureOverride;
use App\Models\PlanFeature;
use App\Models\Subscription;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Resolves an org's boolean / config feature entitlements — the gating sibling of the metered
 * {@see EntitlementsView}. For an org it reads its serving subscription's
 * plan grants ({@see PlanFeature}) and any org-level {@see OrganizationFeatureOverride}, and
 * answers, per feature, whether it is granted and (for a config feature) its typed value.
 *
 * **Resolution order (override wins over plan, deny-by-default underneath):**
 *
 *  1. Baseline: every feature is `enabled: false` (deny-by-default) — a feature nobody grants is
 *     absent, exactly like a meter with no policy.
 *  2. Plan grant: the serving plan's `plan_features` row (enabled) turns it on, carrying the
 *     plan's config value.
 *  3. Org override: an `organization_feature_overrides` row is the last word — `granted: true`
 *     forces it on (with the override's value, or the plan's when the override leaves it null);
 *     `granted: false` forces it off even when the plan grants it.
 *
 * **Serving** is the engine's serving set (via {@see Subscription::scopeServing()}) — a trialing,
 * past-due or non-renewing org keeps its features; a paused/absent subscription grants none
 * (an org-level override can still grant a feature with no subscription at all).
 *
 * **Request-level memoization (reuses PERF-2):** the serving plan, its grant rows, the org's
 * overrides and the feature catalog are read ONCE per org and cached on the instance. The
 * resolver is a per-request container singleton, so the memo lives exactly one request;
 * {@see flush()} clears it when a caller mutates a grant/override/subscription and re-resolves
 * within the same request (wired to those models' saved/deleted events in the service provider).
 *
 * **Cross-request cache (PERF-4):** the same per-org context is also cached in the shared cache
 * store for a short TTL, so back-to-back requests for the same org (the console customer page,
 * the `/features` API a client polls) resolve without re-reading the catalog + grants + overrides
 * every time. The cache key carries a global epoch counter that {@see flush()} bumps, so a
 * grant/override/subscription write invalidates every org at once (mirroring the request memo);
 * the short TTL bounds staleness even if a bust is ever missed.
 */
class FeatureEntitlements
{
    /** Cross-request context TTL (seconds) — short, since a write also bumps the epoch. */
    private const CACHE_TTL = 60;

    /** The cache key holding the global epoch; bumped on every flush to rotate all org keys. */
    private const EPOCH_KEY = 'feature-entitlements:epoch';

    /**
     * Per-org resolution context, memoized for the request.
     *
     * @var array<string, array{plan_id: int|null, features: array<int, Feature>, grants: array<int, PlanFeature>, overrides: array<int, OrganizationFeatureOverride>}>
     */
    private array $memo = [];

    public function __construct(private Cache $cache) {}

    /**
     * The org's full resolved feature set, keyed by feature key. Every feature that is live
     * (non-archived), OR that its serving plan grants, OR that it has an override for, is
     * present — so a grant is never hidden by archival, and deny-by-default features still
     * report `enabled: false` rather than being omitted.
     *
     * @return array<string, ResolvedFeature>
     */
    public function forOrganization(string $org): array
    {
        $context = $this->contextFor($org);
        $resolved = [];

        foreach ($context['features'] as $feature) {
            $grant = $context['grants'][$feature->id] ?? null;
            $override = $context['overrides'][$feature->id] ?? null;

            // Skip an archived feature nobody grants/overrides for this org — it is not part of
            // the org's live surface. A granted or overridden archived feature is still shown.
            if ($feature->isArchived() && $grant === null && $override === null) {
                continue;
            }

            $resolved[$feature->key] = $this->decide($feature, $grant, $override);
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * The resolved answer for a single feature key. An unknown key (not in the catalog) resolves
     * to the deny-by-default answer — `enabled: false`, no value — never a 404, so a caller can
     * gate on any key uniformly.
     */
    public function resolve(string $org, string $key): ResolvedFeature
    {
        $context = $this->contextFor($org);
        $feature = $this->featureByKey($context['features'], $key);

        if (! $feature instanceof Feature) {
            return ResolvedFeature::denied($key);
        }

        return $this->decide(
            $feature,
            $context['grants'][$feature->id] ?? null,
            $context['overrides'][$feature->id] ?? null,
        );
    }

    /** Whether the org has the feature granted (the boolean check the UpgradeGate gates on). */
    public function has(string $org, string $key): bool
    {
        return $this->resolve($org, $key)->enabled;
    }

    /**
     * Drop the memoized per-org context (e.g. after a grant/override/subscription change) and
     * bump the cross-request cache epoch so every org's cached context is invalidated at once.
     * The epoch is stored forever (store-agnostic increment: read-then-write, so it works on
     * stores whose `increment()` refuses a missing key).
     */
    public function flush(): void
    {
        $this->memo = [];
        $this->cache->forever(self::EPOCH_KEY, $this->epoch() + 1);
    }

    /**
     * Apply the resolution order (baseline → plan grant → org override) for one feature.
     */
    private function decide(Feature $feature, ?PlanFeature $grant, ?OrganizationFeatureOverride $override): ResolvedFeature
    {
        // 3. Org override is the last word.
        if ($override instanceof OrganizationFeatureOverride) {
            if (! $override->granted) {
                // A revoke is still an override-sourced decision (enabled false), not the
                // deny-by-default baseline — the console/API shows it came from an override.
                return new ResolvedFeature($feature->key, $feature->type, false, null, FeatureSource::Override);
            }

            // Granted: prefer the override's own value, else fall back to the plan grant's value.
            $raw = $override->value ?? $grant?->value;

            return new ResolvedFeature(
                $feature->key,
                $feature->type,
                true,
                $feature->castValue($raw),
                FeatureSource::Override,
            );
        }

        // 2. Plan grant.
        if ($grant instanceof PlanFeature && $grant->enabled) {
            return new ResolvedFeature(
                $feature->key,
                $feature->type,
                true,
                $feature->castValue($grant->value),
                FeatureSource::Plan,
            );
        }

        // 1. Deny-by-default baseline.
        return ResolvedFeature::denied($feature->key, $feature->type);
    }

    /**
     * @param  array<int, Feature>  $features
     */
    private function featureByKey(array $features, string $key): ?Feature
    {
        foreach ($features as $feature) {
            if ($feature->key === $key) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * The org's resolution context, loaded once and memoized: its serving plan id, that plan's
     * feature-grant rows keyed by feature id, the org's overrides keyed by feature id, and the
     * whole feature catalog keyed by id. An org with no serving subscription still loads its
     * overrides + catalog (an override can grant a feature with no subscription).
     *
     * @return array{plan_id: int|null, features: array<int, Feature>, grants: array<int, PlanFeature>, overrides: array<int, OrganizationFeatureOverride>}
     */
    private function contextFor(string $org): array
    {
        if (isset($this->memo[$org])) {
            return $this->memo[$org];
        }

        return $this->memo[$org] = $this->cache->remember(
            'feature-entitlements:'.$this->epoch().':'.$org,
            self::CACHE_TTL,
            fn (): array => $this->loadContext($org),
        );
    }

    /**
     * Load the org's resolution context from the durable catalog (the DB read wrapped by both the
     * request memo and the cross-request cache).
     *
     * @return array{plan_id: int|null, features: array<int, Feature>, grants: array<int, PlanFeature>, overrides: array<int, OrganizationFeatureOverride>}
     */
    private function loadContext(string $org): array
    {
        $subscription = Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();

        $planId = $subscription instanceof Subscription ? $subscription->plan_id : null;

        /** @var array<int, Feature> $features */
        $features = Feature::query()->orderBy('key')->get()->keyBy('id')->all();

        /** @var array<int, PlanFeature> $grants */
        $grants = $planId === null
            ? []
            : PlanFeature::query()->where('plan_id', $planId)->get()->keyBy('feature_id')->all();

        /** @var array<int, OrganizationFeatureOverride> $overrides */
        $overrides = OrganizationFeatureOverride::query()
            ->where('organization_id', $org)
            ->get()
            ->keyBy('feature_id')
            ->all();

        return [
            'plan_id' => $planId,
            'features' => $features,
            'grants' => $grants,
            'overrides' => $overrides,
        ];
    }

    /** The current global cache epoch (0 when never flushed). */
    private function epoch(): int
    {
        $epoch = $this->cache->get(self::EPOCH_KEY, 0);

        return is_numeric($epoch) ? (int) $epoch : 0;
    }
}
