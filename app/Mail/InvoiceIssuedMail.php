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
 * Sent to the customer's billing contact when a period invoice is finalized. Carries the
 * legal invoice number, the taxed total, and the due date — the copy the customer needs to
 * recognise and pay the charge. Queued (`ShouldQueue`) so finalization never blocks on SMTP.
 */
class InvoiceIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public string $periodLabel,
        public string $issuedAtLabel,
        public string $dueAtLabel,
        public ?string $viewUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invoice '.$this->invoiceNumber.' from Cbox Billing');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice-issued');
    }
}
