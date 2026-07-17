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
 * The trial-ending reminder, sent ahead of a trial's conversion so the customer knows the
 * free trial is about to become a paying subscription (and its first charge is coming)
 * before it lands. Carries the plan, the trial-end date, and the recurring amount. Queued
 * from the trial-conversion pass.
 */
class TrialEndingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $endsAtLabel,
        public string $amountFormatted,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->planName.' trial ends on '.$this->endsAtLabel);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.trial-ending');
    }
}
