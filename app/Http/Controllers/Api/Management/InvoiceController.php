<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Api\ApiController;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/invoices/{org}` — the org's issued invoices, newest first:
 * `{number, date, amount_minor, currency, status}`. Amounts are integer minor units of
 * the invoice's currency (the account's locked currency). Per-org scoped; a straight read
 * of the {@see Invoice} model.
 */
class InvoiceController extends ApiController
{
    public function __invoke(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $invoices = Invoice::query()
            ->where('organization_id', $org)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Invoice $invoice): array => [
                'number' => $invoice->number,
                'date' => $invoice->issued_at?->toIso8601String(),
                'amount_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
            ])
            ->all();

        return new JsonResponse(['data' => $invoices]);
    }
}
