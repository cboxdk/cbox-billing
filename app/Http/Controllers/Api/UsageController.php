<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Metering\CumulativeUsageIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/usage` — ingest cumulative usage readings. Each entry is a monotically
 * increasing `{meter, cumulative, seq}` reading; the ingest converts it to the delta the
 * immutable event log stores and dedups it, so the SDK may retry freely and billing may
 * reprocess without double-counting.
 *
 * Response: `{ok: true, accepted: <n>}`.
 */
class UsageController extends ApiController
{
    public function __invoke(Request $request, CumulativeUsageIngest $ingest): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.meter' => ['required', 'string'],
            'entries.*.cumulative' => ['required', 'integer', 'min:0'],
            'entries.*.seq' => ['required', 'integer', 'min:0'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $entries = [];

        foreach ($request->array('entries') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $meter = $entry['meter'] ?? null;
            $cumulative = $entry['cumulative'] ?? null;
            $seq = $entry['seq'] ?? null;

            if (! is_string($meter) || ! is_int($cumulative) || ! is_int($seq)) {
                continue;
            }

            $entries[] = ['meter' => $meter, 'cumulative' => $cumulative, 'seq' => $seq];
        }

        $accepted = $ingest->ingest($org, $entries);

        return new JsonResponse(['ok' => true, 'accepted' => $accepted]);
    }
}
