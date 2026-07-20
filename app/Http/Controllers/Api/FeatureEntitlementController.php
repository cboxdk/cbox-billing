<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Billing\Features\FeatureEntitlements;
use App\Billing\Features\FeatureEntitlementsView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The boolean / non-metered feature-entitlements API — the product-gating sibling of the metered
 * `/entitlements/{org}`. Two org-scoped, cacheable reads over the same {@see FeatureEntitlements}
 * resolver a capability check gates on, so what a client caches is exactly what the server would
 * decide. Deny-by-default: a feature nobody grants is `enabled: false`, never omitted; an unknown
 * key resolves to `enabled: false` rather than a 404.
 *
 *  - `GET /entitlements/{org}/features` — the org's whole resolved feature set,
 *    `{features: {key: {type, enabled, value, source, upgrade?}}}`. A not-granted feature with a
 *    reachable plan also carries an `upgrade: {required_plan, checkout_url}` offer (#52).
 *  - `GET /entitlements/{org}/features/{key}` — a single boolean/typed check,
 *    `{key, type, enabled, value, source, upgrade?}`.
 */
class FeatureEntitlementController extends ApiController
{
    /** The org's whole resolved feature set, each not-granted feature carrying its upgrade path. */
    public function index(Request $request, string $org, FeatureEntitlementsView $view, UpgradeGate $upgrades): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $features = $upgrades->enrichFeatures($org, $view->forOrganization($org));

        return new JsonResponse(['features' => $features]);
    }

    /** A single boolean/typed feature check, with the upgrade path when it is not granted. */
    public function show(Request $request, string $org, string $key, FeatureEntitlements $features, UpgradeGate $upgrades): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $resolved = $features->resolve($org, $key);
        $payload = ['key' => $resolved->key] + $resolved->toArray();

        // A not-granted feature carries its upgrade path when a reachable plan would grant it.
        if (! $resolved->enabled) {
            $offer = $upgrades->forFeature($org, $key);

            if ($offer !== null) {
                $payload['upgrade'] = $offer;
            }
        }

        return new JsonResponse($payload);
    }
}
