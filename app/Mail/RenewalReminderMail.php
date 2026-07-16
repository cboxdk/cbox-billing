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
 * The renewal reminder, sent ahead of a subscription's term renewal so the customer knows
 * the next period (and its charge) is coming before it lands. Carries the plan, the renewal
 * date, and the recurring amount. Queued from the renewal pass.
 */
class RenewalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $renewsAtLabel,
        public string $amountFormatted,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->planName.' subscription renews on '.$this->renewsAtLabel);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.renewal-reminder');
    }
}
