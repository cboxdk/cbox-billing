<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Enforcement\Contracts\ReservationStore;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/commit` — settle a reservation to the actual usage per meter. The held set
 * is reloaded by `reservation_id`, committed through the engine's
 * {@see Enforcement::commitBuckets()} (which returns unused allowance/lease and appends one
 * durable usage event per bucket with non-zero usage), then dropped.
 *
 * Response: `{ok: true}`. A missing/expired reservation is 404; an actual exceeding its
 * reserved estimate is a 422 from the engine's own validation.
 */
class CommitController extends ApiController
{
    public function __invoke(Request $request, Enforcement $enforcement, ReservationStore $reservations): JsonResponse
    {
        $request->validate([
            'reservation_id' => ['required', 'string'],
            'actuals' => ['required', 'array', 'min:1'],
            'actuals.*.meter' => ['required', 'string'],
            'actuals.*.actual' => ['required', 'integer', 'min:0'],
        ]);

        $reservationId = $request->string('reservation_id')->toString();

        $set = $reservations->get($reservationId);

        if ($set === null) {
            return new JsonResponse(
                ['error' => 'Unknown or expired reservation.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($denied = $this->denyUnlessMayActFor($request, $set->org)) {
            return $denied;
        }

        $actuals = [];

        foreach ($request->array('actuals') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $meter = $row['meter'] ?? null;
            $actual = $row['actual'] ?? null;

            if (! is_string($meter) || ! is_int($actual)) {
                continue;
            }

            $actuals[$meter] = $actual;
        }

        $enforcement->commitBuckets($set, $actuals);
        $reservations->forget($reservationId);

        return new JsonResponse(['ok' => true]);
    }
}
