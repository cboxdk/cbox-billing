<?php

declare(strict_types=1);

namespace App\Billing\Refunds;

use App\Billing\Mode\BillingContext;
use App\Billing\Seller\SellerCatalog;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Invoice\ValueObjects\CreditNote;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\ValueObjects\Refund;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The durable {@see RefundRepository} — the record of issued refunds on the same
 * connection as the ledger, so the refund record and the reversing posting commit
 * together. It is what makes the engine refunder idempotent ({@see forId()} short-circuits
 * a retry BEFORE a credit-note number is drawn) and bounds the refund to the amount
 * charged ({@see refundedGross()} is the cumulative cap).
 *
 * A stored row carries enough to reconstruct the engine {@see Refund} for an idempotent
 * replay; the credit note's own lines live on the display-facing `credit_notes` table
 * (fed by the {@see CreditNoteIssued} event), which the replay does
 * not need.
 */
readonly class DatabaseRefundRepository implements RefundRepository
{
    private const TABLE = 'refunds';

    public function __construct(
        private ConnectionInterface $db,
        private SellerCatalog $sellers,
        private BillingContext $context,
    ) {}

    public function forId(string $refundId): ?Refund
    {
        $row = $this->db->table(self::TABLE)
            ->where('refund_id', $refundId)
            ->where('environment', $this->context->environmentKey())
            ->first();

        if ($row === null) {
            return null;
        }

        $currency = $this->str($row->currency);
        $gross = Money::ofMinor($this->int($row->gross_minor), $currency);
        $net = Money::ofMinor($this->int($row->net_minor), $currency);
        $tax = Money::ofMinor($this->int($row->tax_minor), $currency);
        $kind = ReversalKind::from($this->str($row->kind));

        $creditNote = new CreditNote(
            number: $this->str($row->credit_note_number),
            invoiceNumber: $this->str($row->invoice_number),
            seller: $this->sellers->entity($this->str($row->seller)),
            account: $this->str($row->account),
            currency: $currency,
            lines: [],
            net: $net->negated(),
            tax: $tax->negated(),
            gross: $gross->negated(),
            reason: RefundReason::from($this->str($row->reason)),
            kind: $kind,
            issuedAt: new DateTimeImmutable($this->str($row->issued_at)),
        );

        return new Refund(
            id: $refundId,
            creditNote: $creditNote,
            account: $this->str($row->account),
            gross: $gross,
            gatewayResult: new PaymentResult(
                PaymentStatus::from($this->str($row->gateway_status)),
                $row->gateway_reference !== null ? $this->str($row->gateway_reference) : null,
            ),
            grantReversalId: $row->grant_reversal_id !== null ? $this->str($row->grant_reversal_id) : null,
            ledgerTransactionId: $this->str($row->ledger_transaction_id),
            kind: $kind,
        );
    }

    public function refundedGross(string $invoiceNumber, string $currency): Money
    {
        $sum = $this->db->table(self::TABLE)
            ->where('invoice_number', $invoiceNumber)
            ->where('currency', $currency)
            ->where('environment', $this->context->environmentKey())
            ->sum('gross_minor');

        return Money::ofMinor((int) $sum, $currency);
    }

    public function save(Refund $refund): void
    {
        $note = $refund->creditNote;

        $this->db->table(self::TABLE)->updateOrInsert(
            ['refund_id' => $refund->id],
            [
                'environment' => $this->context->environmentKey(),
                'livemode' => $this->context->livemode(),
                'invoice_number' => $note->invoiceNumber,
                'credit_note_number' => $note->number,
                'account' => $refund->account,
                'seller' => $note->seller->id,
                'currency' => $refund->gross->currency(),
                'net_minor' => $note->net->negated()->minor(),
                'tax_minor' => $note->tax->negated()->minor(),
                'gross_minor' => $refund->gross->minor(),
                'reason' => $note->reason->value,
                'ledger_transaction_id' => $refund->ledgerTransactionId,
                'grant_reversal_id' => $refund->grantReversalId,
                'kind' => $refund->kind->value,
                'gateway_status' => $refund->gatewayResult->status->value,
                'gateway_reference' => $refund->gatewayResult->gatewayReference,
                'issued_at' => $note->issuedAt->format('Y-m-d H:i:s'),
                'updated_at' => $this->db->raw('CURRENT_TIMESTAMP'),
                'created_at' => $this->db->raw('CURRENT_TIMESTAMP'),
            ],
        );
    }

    /** Narrow a query-row scalar to a string. */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Narrow a query-row scalar to an int. */
    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
