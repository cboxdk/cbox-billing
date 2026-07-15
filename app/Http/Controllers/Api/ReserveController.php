<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Enforcement\Contracts\ReservationStore;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Enums\DenialReason;
use Cbox\Billing\Metering\Enums\OutcomeStatus;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;
use Cbox\Billing\Metering\ValueObjects\InfraFault;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/reserve` — reserve a set of meter buckets in one all-or-nothing call via
 * the engine's {@see Enforcement::reserveBucketsOutcome()}. The engine's three-way outcome
 * is mapped straight onto the JSON the SDK expects:
 *
 *  - `Allowed`       → `{outcome: "allowed", reservation_id}` (the held set is persisted
 *                       for the matching `/commit`).
 *  - `Denied`        → `{outcome: "denied", reason}` — a semantic refusal (unknown/disabled
 *                       meter, exhausted allowance/quota); fail-closed.
 *  - `Indeterminate` → `{outcome: "indeterminate", reason}` — a dependency was down; the
 *                       deployment's infra policy decided admit/refuse and it is signalled.
 */
class ReserveController extends ApiController
{
    public function __invoke(Request $request, Enforcement $enforcement, ReservationStore $reservations, Config $config): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'meters' => ['required', 'array', 'min:1'],
            'meters.*.meter' => ['required', 'string'],
            'meters.*.estimate' => ['required', 'integer', 'min:1'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $requests = [];

        foreach ($request->array('meters') as $meter) {
            if (! is_array($meter)) {
                continue;
            }

            $key = $meter['meter'] ?? null;
            $estimate = $meter['estimate'] ?? null;

            if (! is_string($key) || ! is_int($estimate)) {
                continue;
            }

            $requests[] = new BucketRequest($key, $estimate);
        }

        $outcome = $enforcement->reserveBucketsOutcome($org, $requests);

        if ($outcome->status === OutcomeStatus::Allowed) {
            return $this->allowed($outcome, $reservations, $config);
        }

        if ($outcome->status === OutcomeStatus::Denied) {
            $reason = $outcome->reason;

            return new JsonResponse([
                'outcome' => 'denied',
                'reason' => $reason instanceof DenialReason ? $reason->value : 'denied',
            ]);
        }

        $fault = $outcome->fault;

        return new JsonResponse([
            'outcome' => 'indeterminate',
            'reason' => $fault instanceof InfraFault ? $fault->reason : 'dependency unavailable',
        ]);
    }

    private function allowed(EnforcementOutcome $outcome, ReservationStore $reservations, Config $config): JsonResponse
    {
        $set = $outcome->reservationSet();

        $ttl = $config->get('billing.api.reservation_ttl_seconds', 300);
        $ttl = is_numeric($ttl) ? (int) $ttl : 300;

        $reservations->put($set, $ttl);

        return new JsonResponse([
            'outcome' => 'allowed',
            'reservation_id' => $set->id,
        ]);
    }
}
