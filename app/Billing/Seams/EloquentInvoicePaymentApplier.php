<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Payments\Exceptions\SettlementRejected;
use App\Billing\Support\MoneyFormatter;
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
 *
 * Money integrity: the settled amount + currency MUST match the invoice gross. A mismatch
 * (a settlement claiming the wrong amount/currency) is REFUSED by {@see Invoice::markPaid()},
 * flagged in the audit log for ops, and signalled to the ingest with a {@see SettlementRejected}
 * so it aborts BEFORE committing the settle-once guard — never marked paid, never receipted, and
 * crucially never consuming the dedup guard that a later CORRECT settlement retry needs.
 */
readonly class EloquentInvoicePaymentApplier implements InvoicePaymentApplier
{
    public function __construct(
        private NotifiesCustomers $notifier,
        private RecordsAudit $audit,
    ) {}

    /**
     * @throws SettlementRejected when the settled amount/currency does not match the invoice gross.
     */
    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $invoice = Invoice::query()->where('number', $reference)->first();

        if ($invoice === null) {
            return;
        }

        $wasPaid = $invoice->isPaid();

        if (! $invoice->markPaid($amount, $result->gatewayReference)) {
            // The settled amount/currency did not match the invoice gross — flag it for ops and
            // leave the invoice unpaid (deny-by-default). No receipt is sent for a non-payment.
            $this->audit->record(
                AuditAction::InvoiceSettlementRejected,
                AuditTarget::model($invoice, $invoice->organization_id),
                sprintf(
                    'Settlement rejected for invoice %s: received %s, expected %s.',
                    $invoice->number,
                    MoneyFormatter::money($amount),
                    MoneyFormatter::money($invoice->total()),
                ),
                [
                    'received_minor' => $amount->minor(),
                    'received_currency' => $amount->currency(),
                    'expected_minor' => $invoice->total_minor,
                    'expected_currency' => $invoice->currency,
                    'gateway_reference' => $result->gatewayReference,
                ],
            );

            // Signal the rejection so the ingest aborts before writing the settle-once / processed
            // guards: a subsequent correct-amount settlement for this same invoice must still apply.
            throw SettlementRejected::forReference($reference, sprintf(
                'Settlement rejected for invoice %s: amount/currency did not match the invoice gross.',
                $invoice->number,
            ));
        }

        // Only a genuine unpaid → paid transition gets a receipt (exactly-once, riding the
        // settlement the engine applied — never a redelivery that finds it already paid).
        if (! $wasPaid && $invoice->isPaid()) {
            $this->notifier->paymentReceipt($invoice);
        }
    }
}
