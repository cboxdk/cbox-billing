<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * Sent to the customer's billing contact when a period invoice is finalized. Carries the
 * legal invoice number, the taxed total, and the due date — the copy the customer needs to
 * recognise and pay the charge. Queued so finalization never blocks on SMTP; rendered through
 * the branded, localized template system (see {@see TransactionalMailable}).
 */
class InvoiceIssuedMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public string $periodLabel,
        public string $issuedAtLabel,
        public string $dueAtLabel,
        public ?string $viewUrl = null,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::InvoiceIssued;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'invoice_number' => $this->invoiceNumber,
            'amount_formatted' => $this->amountFormatted,
            'period_label' => $this->periodLabel,
            'issued_at_label' => $this->issuedAtLabel,
            'due_at_label' => $this->dueAtLabel,
            'view_url' => $this->viewUrl ?? '',
        ];
    }
}
