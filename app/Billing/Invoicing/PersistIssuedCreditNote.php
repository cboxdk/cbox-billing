<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Illuminate\Support\Carbon;

/**
 * Persists the app's {@see CreditNote} record surface from the engine's
 * {@see CreditNoteIssued} event — the one place a durable credit note is created, so it
 * always mirrors a real engine-issued reversal (a refund or an adjustment) rather than a
 * hand-authored row.
 *
 * The engine's credit-note aggregate carries NEGATIVE (mirrored) net/tax/gross; the app
 * stores POSITIVE magnitudes (the reversal sign is the document's meaning). Idempotent on
 * the credit note number, so a synchronous re-dispatch never persists twice.
 */
class PersistIssuedCreditNote
{
    public function handle(CreditNoteIssued $event): void
    {
        $note = $event->creditNote;

        if (CreditNote::query()->where('number', $note->number)->exists()) {
            return;
        }

        $invoice = Invoice::query()->where('number', $note->invoiceNumber)->first();

        $creditNote = CreditNote::query()->create([
            'number' => $note->number,
            'invoice_number' => $note->invoiceNumber,
            'invoice_id' => $invoice?->id,
            'organization_id' => $note->account,
            'seller' => $note->seller->id,
            'currency' => $note->currency,
            'net_minor' => $note->net->negated()->minor(),
            'tax_minor' => $note->tax->negated()->minor(),
            'gross_minor' => $note->gross->negated()->minor(),
            'reason' => $note->reason->value,
            'kind' => $note->kind->value,
            'issued_at' => Carbon::instance($note->issuedAt),
        ]);

        foreach ($note->lines as $line) {
            /** @var QuoteLine $line */
            CreditNoteLine::query()->create([
                'credit_note_id' => $creditNote->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'net_minor' => $line->net->negated()->minor(),
                'tax_minor' => $line->tax->negated()->minor(),
                'gross_minor' => $line->gross->negated()->minor(),
            ]);
        }
    }
}
