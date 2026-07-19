<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The payment receipt, sent to the billing contact when a settled-payment webhook marks an
 * invoice paid. Queued so the webhook path stays fast and exactly-once (the send rides the
 * applied settlement, never a re-delivery); rendered through the branded, localized template
 * system (see {@see TransactionalMailable}).
 */
class PaymentReceiptMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public string $paidAtLabel,
        public ?string $gatewayReference = null,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::PaymentReceipt;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'invoice_number' => $this->invoiceNumber,
            'amount_formatted' => $this->amountFormatted,
            'paid_at_label' => $this->paidAtLabel,
            'gateway_reference' => $this->gatewayReference ?? '',
        ];
    }
}
