<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Metering\UsageSummaryView;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/usage/{org}` — the org's per-meter usage breakdown for the current billing
 * period (#55): `{period, meters:{meter:{used, allowance, overage, projected,
 * projected_overage}}}`. `used` is the reconciled total from the durable event log (not a
 * hot-path counter) and `projected` is its straight-line end-of-period extrapolation.
 * Per-org scoped and thin — delegate to {@see UsageSummaryView}.
 */
class UsageController extends ApiController
{
    public function __invoke(Request $request, string $org, UsageSummaryView $view): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        return new JsonResponse($view->forOrganization($org));
    }
}
