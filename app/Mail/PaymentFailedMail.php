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
 * The dunning notice, sent to the billing contact at each dunning step for a past-due
 * account. It states the outstanding amount and — when the account has crossed into
 * suspension — that access is now gated, so the customer is never suspended un-warned. The
 * `suspended` flag drives the copy and the accent (a warning reminder vs a suspension notice).
 */
class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $amountDueFormatted,
        public bool $suspended,
        public ?string $oldestDueLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->suspended
            ? 'Your Cbox account has been suspended for non-payment'
            : 'Payment reminder — action needed on your Cbox account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-failed');
    }
}
