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
 * The smart-retry payment-failed notice, sent to the billing contact at each step of a
 * failed renewal charge's retry schedule: the initial failure (`attempt` 0), each
 * automated retry that fails, and — when `exhausted` — the final give-up notice. It states
 * the invoice, the amount, and when the next attempt will run (or that retries are
 * exhausted), so the customer can fix their payment method before the subscription lapses.
 */
class PaymentRetryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public int $attempt,
        public int $maxAttempts,
        public ?string $nextAttemptLabel = null,
        public bool $exhausted = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->exhausted
            ? 'We couldn\'t process your payment — action needed'
            : 'Payment failed — we\'ll try again shortly');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payment-retry');
    }
}
