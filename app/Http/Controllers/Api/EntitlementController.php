<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Metering\EntitlementsView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/entitlements/{org}` — the org's resolved per-meter policies, the payload the
 * SDK caches to enforce locally. Read through the same meter-policy resolver the enforcer
 * uses, so the SDK sees exactly what the server would decide; a meter with no resolved
 * policy is reported disabled (deny-by-default), never omitted.
 *
 * Response: `{meters: {meter: {enabled, allowance, weight, overage}}}`.
 */
class EntitlementController extends ApiController
{
    public function __invoke(Request $request, string $org, EntitlementsView $view): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        return new JsonResponse(['meters' => $view->forOrganization($org)]);
    }
}
