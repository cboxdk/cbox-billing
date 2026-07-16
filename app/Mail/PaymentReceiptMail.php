<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The payment receipt, sent to the billing contact when a settled-payment webhook marks an
 * invoice paid. Confirms the amount received against the invoice number and the settlement
 * date. Queued so the webhook path stays fast and exactly-once (the send rides the applied
 * settlement, never a re-delivery).
 */
class PaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public string $paidAtLabel,
        public ?string $gatewayReference = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment received for invoice '.$this->invoiceNumber);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-receipt');
    }
}
