<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Billing\Metering\EntitlementsView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/entitlements/{org}` — the org's resolved per-meter policies, the payload the
 * SDK caches to enforce locally. Read through the same meter-policy resolver the enforcer
 * uses, so the SDK sees exactly what the server would decide; a meter with no resolved
 * policy is reported disabled (deny-by-default), never omitted.
 *
 * Response: `{meters: {meter: {enabled, allowance, weight, overage, upgrade?}}}` — a
 * disabled meter with a reachable upgrade path also carries
 * `upgrade: {required_plan, checkout_url}` (#52).
 */
class EntitlementController extends ApiController
{
    public function __invoke(Request $request, string $org, EntitlementsView $view, UpgradeGate $upgrades): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        // Each disabled meter carries its upgrade path (#52) — the minimum reachable plan
        // that enables it and a pre-built checkout deep-link — when one exists.
        $meters = $upgrades->enrichEntitlements($org, $view->forOrganization($org));

        return new JsonResponse(['meters' => $meters]);
    }
}
