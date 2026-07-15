<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Models\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The host effect a settled webhook applies: mark the app {@see Invoice} behind a
 * reference paid. The engine drives this at most once per reference through its
 * exactly-once webhook ingest (guarded by the settle-once store), so the applier does
 * not have to be idempotent — though {@see Invoice::markPaid()} is anyway.
 *
 * The `$reference` the engine carries is the invoice's document `number`.
 */
readonly class EloquentInvoicePaymentApplier implements InvoicePaymentApplier
{
    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $invoice = Invoice::query()->where('number', $reference)->first();

        $invoice?->markPaid($amount, $result->gatewayReference);
    }
}
