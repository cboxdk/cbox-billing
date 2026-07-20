<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Models\Invoice;
use Cbox\Billing\Refund\Enums\RefundReason;

/**
 * Builds a {@see RefundInvoiceAction} from a validated payload — used identically by the
 * controller (direct path) and the executor (approval path), re-loading the invoice by id so a
 * held refund runs against the current invoice row when it is finally approved.
 */
readonly class RefundInvoiceActionFactory implements BuildsApprovableAction
{
    public function __construct(
        private RunsInvoiceOperations $operations,
        private RecordsAudit $audit,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::InvoiceRefund;
    }

    public function build(array $payload): RefundInvoiceAction
    {
        $invoiceId = $payload['invoice_id'] ?? null;
        $invoice = Invoice::query()->findOrFail(is_scalar($invoiceId) ? $invoiceId : null);

        $netMinor = $payload['net_minor'] ?? null;
        $reason = $payload['reason'] ?? null;

        return new RefundInvoiceAction(
            $this->operations,
            $this->audit,
            $invoice,
            is_numeric($netMinor) ? (int) $netMinor : null,
            RefundReason::from(is_string($reason) ? $reason : ''),
            is_scalar($payload['idempotency_key'] ?? null) ? (string) $payload['idempotency_key'] : '',
        );
    }
}
