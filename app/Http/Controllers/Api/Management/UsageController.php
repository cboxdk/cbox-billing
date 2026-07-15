<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Metering\UsageSummaryView;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/usage/{org}` — the org's usage-against-allowance summary for the current
 * billing period: `{period, meters:{meter:{used, allowance, overage}}}`. `used` is the
 * reconciled total from the durable event log, not a hot-path counter. Per-org scoped and
 * thin — delegate to {@see UsageSummaryView}.
 */
class UsageController extends ApiController
{
    public function __invoke(Request $request, string $org, UsageSummaryView $view): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        return new JsonResponse($view->forOrganization($org));
    }
}
