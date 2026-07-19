<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\Contracts;

use App\Billing\Invoicing\ValueObjects\ManualLine;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Refund\Contracts\Refunder;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\ValueObjects\Refund;

/**
 * The invoice lifecycle-operations surface (Wave 3): void, refund (→ credit note through
 * the engine refunder), record a manual/offline payment, re-queue the invoice email, and
 * create an ad-hoc invoice. Every money movement flows through the engine primitives
 * (the {@see Refunder}, the
 * {@see InvoicePaymentApplier}, the
 * {@see Invoicer}) — never hand-rolled — and every guard
 * is enforced here server-side, not on the confirm dialog.
 */
interface RunsInvoiceOperations
{
    /**
     * Void an open/uncollectible invoice. Refuses a paid or already-voided invoice
     * (deny-by-default) — a settled invoice is reversed with a refund/credit note, never
     * voided.
     */
    public function void(Invoice $invoice): void;

    /**
     * Refund (part of) an issued invoice: `$net` null is a FULL refund (mirror every
     * line, tax and all); a `$net` set is a PARTIAL refund of that net amount (tax
     * reversed proportionally). Issues a credit note off the seller's own sequence and
     * reverses the ledger + gateway through the engine refunder. Idempotent on
     * `$actionId`; refuses an unissued invoice or an over-refund.
     */
    public function refund(Invoice $invoice, ?int $netMinor, RefundReason $reason, string $actionId): Refund;

    /**
     * Record a manual/offline settlement (bank transfer, cash) as a payment on the
     * invoice. Idempotent — a re-run on an already-paid invoice is a no-op.
     */
    public function markPaid(Invoice $invoice, ?string $reference): void;

    /** Re-queue the issued-invoice email to the account's billing contact. */
    public function resend(Invoice $invoice): void;

    /**
     * Issue an ad-hoc/one-off invoice for `$organization` from operator-authored lines:
     * priced + taxed through the engine quote builder, numbered + currency-locked through
     * the engine invoicer, then persisted. Refuses when there are no positive lines or the
     * quote is tax-pending.
     *
     * @param  list<ManualLine>  $lines
     */
    public function createManual(Organization $organization, array $lines): Invoice;
}
