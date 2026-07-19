<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Api\CursorPaginator;
use App\Http\Controllers\Api\ApiController;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/invoices/{org}` — the org's issued invoices, newest first:
 * `{number, date, amount_minor, currency, status}`. Amounts are integer minor units of
 * the invoice's currency (the account's locked currency). Per-org scoped; a straight read
 * of the {@see Invoice} model, cursor-paginated (`?limit=`/`?cursor=` → `has_more` +
 * `next_cursor`) so a large history streams in stable pages rather than one unbounded blob.
 */
class InvoiceController extends ApiController
{
    public function __invoke(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        // Keyset on the monotonic primary key (descending = newest-first) for a stable cursor.
        $page = CursorPaginator::fromQuery(
            Invoice::query()->where('organization_id', $org),
            $request,
        );

        return new JsonResponse($page->envelope(static fn (Invoice $invoice): array => [
            'number' => $invoice->number,
            'date' => $invoice->issued_at?->toIso8601String(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
        ]));
    }
}
