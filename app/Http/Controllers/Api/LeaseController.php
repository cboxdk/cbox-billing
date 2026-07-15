<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/leases` — lease a slice of an org's remaining allowance for a meter. This
 * is billing's side of the pessimistic lease the SDK's local enforcement refills from:
 * the central budget grants up to `size` units (fewer when the includable allowance is
 * nearly spent, 0 when exhausted) and holds them until returned or expired.
 *
 * Response: `{lease_id, granted, expires_at}`.
 */
class LeaseController extends ApiController
{
    public function __invoke(Request $request, AllowanceLeaseSource $source, Config $config): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'meter' => ['required', 'string'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $lease = $source->lease($org, $request->string('meter')->toString(), $request->integer('size'));

        $ttl = $config->get('billing.api.lease_ttl_seconds', 300);
        $ttl = is_numeric($ttl) ? (int) $ttl : 300;

        return new JsonResponse([
            'lease_id' => 'lease_'.Str::random(24),
            'granted' => $lease->granted,
            'expires_at' => Carbon::now()->addSeconds($ttl)->toIso8601String(),
        ]);
    }
}
