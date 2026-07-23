<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The operator-facing economic-nexus alert: an internal ops notice that one or more US states
 * crossed into Approaching or Triggered for the default seller. Unlike the customer lifecycle
 * mail, this is a plain operator notification (no per-tenant branding/localization) sent to the
 * configured operations recipients — a compliance signal to register where an obligation now
 * exists.
 */
class NexusAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{state: string, status: string, threshold: string, progress: string, reason: string}>  $rows
     *                                                                                                                 the newly-crossed states, pre-formatted for display (a view/serialization boundary)
     * @param  bool  $soleSalesChannel  whether this platform is the seller's only US sales channel
     */
    public function __construct(
        public array $rows,
        public bool $soleSalesChannel,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->rows);

        return new Envelope(
            subject: sprintf('US economic nexus: %d state%s need attention', $count, $count === 1 ? '' : 's'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.nexus-alert');
    }
}
