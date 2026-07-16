<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
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
 * The `$reference` the engine carries is the invoice's document `number`. When (and only
 * when) an invoice actually transitions to paid, the customer receipt is queued — a
 * re-delivered settlement that no-ops the already-paid invoice sends no second receipt.
 */
readonly class EloquentInvoicePaymentApplier implements InvoicePaymentApplier
{
    public function __construct(private NotifiesCustomers $notifier) {}

    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $invoice = Invoice::query()->where('number', $reference)->first();

        if ($invoice === null) {
            return;
        }

        $wasPaid = $invoice->isPaid();

        $invoice->markPaid($amount, $result->gatewayReference);

        // Only a genuine unpaid → paid transition gets a receipt (exactly-once, riding the
        // settlement the engine applied — never a redelivery that finds it already paid).
        if (! $wasPaid && $invoice->isPaid()) {
            $this->notifier->paymentReceipt($invoice);
        }
    }
}
