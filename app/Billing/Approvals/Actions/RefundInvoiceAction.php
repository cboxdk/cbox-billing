<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Invoicing\Exceptions\InvoiceActionDenied;
use App\Billing\Support\MoneyFormatter;
use App\Models\Invoice;
use Cbox\Billing\Refund\Enums\RefundReason;

/**
 * Held action for an invoice refund (money out → credit note). {@see execute()} is the SAME
 * path the direct console refund took: it reverses the invoice through the engine
 * {@see RunsInvoiceOperations::refund()} and records the `invoice.refunded` audit event — so an
 * approved refund is indistinguishable from a direct one.
 *
 * Idempotency: the engine refunder is idempotent on the action id (`op-refund:<key>`), so even
 * if execution were retried it never issues a second credit note; the engine also caps the
 * cumulative refund at the amount charged.
 */
readonly class RefundInvoiceAction implements ApprovableAction
{
    /** Invoice statuses a refund may reverse (mirrors the engine guard). */
    private const REFUNDABLE = ['open', 'paid', 'uncollectible'];

    public function __construct(
        private RunsInvoiceOperations $operations,
        private RecordsAudit $audit,
        private Invoice $invoice,
        private ?int $netMinor,
        private RefundReason $reason,
        private string $idempotencyKey,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::InvoiceRefund;
    }

    public function context(): ApprovalContext
    {
        return new ApprovalContext(
            organizationId: $this->invoice->organization_id,
            amountMinor: $this->netMinor ?? $this->invoice->total_minor,
            currency: $this->invoice->currency,
            targetType: 'invoice',
            targetId: (string) $this->invoice->id,
        );
    }

    public function payload(): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'net_minor' => $this->netMinor,
            'reason' => $this->reason->value,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    public function validate(): void
    {
        if (! in_array($this->invoice->status, self::REFUNDABLE, true)) {
            throw InvoiceActionDenied::notRefundable($this->invoice->status);
        }
    }

    public function describe(): ApprovalDescription
    {
        $mode = $this->netMinor === null ? 'Full refund' : 'Partial refund';
        $amount = MoneyFormatter::minor($this->netMinor ?? $this->invoice->total_minor, $this->invoice->currency);

        return new ApprovalDescription(
            sprintf('%s of invoice %s (%s), reason: %s', $mode, $this->invoice->number, $amount, $this->reason->value),
            before: ['status' => $this->invoice->status, 'total_minor' => $this->invoice->total_minor],
            after: ['status' => 'refunded', 'refund_net_minor' => $this->netMinor ?? $this->invoice->total_minor],
        );
    }

    public function execute(): ApprovalOutcome
    {
        $before = ['status' => $this->invoice->status, 'total_minor' => $this->invoice->total_minor];

        $refund = $this->operations->refund(
            $this->invoice,
            $this->netMinor,
            $this->reason,
            'op-refund:'.$this->idempotencyKey,
        );

        $this->audit->record(
            AuditAction::InvoiceRefunded,
            AuditTarget::model($this->invoice),
            sprintf('Refunded invoice %s as credit note %s (%s).', $this->invoice->number, $refund->creditNote->number, MoneyFormatter::money($refund->gross)),
            [
                'before' => $before,
                'after' => ['status' => $this->invoice->fresh()?->status, 'credit_note' => $refund->creditNote->number, 'refund_gross_minor' => $refund->gross->minor()],
                'mode' => $this->netMinor === null ? 'full' : 'partial',
                'reason' => $this->reason->value,
            ],
        );

        return new ApprovalOutcome(
            sprintf('Refund issued as credit note %s (%s).', $refund->creditNote->number, MoneyFormatter::money($refund->gross)),
            ['credit_note' => $refund->creditNote->number, 'refund_gross_minor' => $refund->gross->minor()],
        );
    }
}
