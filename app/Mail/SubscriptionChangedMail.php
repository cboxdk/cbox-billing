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
 * Confirms a subscription change to the billing contact: a plan switch, or a cancellation
 * (immediate or scheduled for the period end). `changeType` is `plan_change`, `canceled` or
 * `cancel_scheduled`; the view keys its copy off it. Queued from the lifecycle service.
 */
class SubscriptionChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $changeType,
        public string $planName,
        public ?string $previousPlanName = null,
        public ?string $effectiveAtLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->changeType) {
            'canceled', 'cancel_scheduled' => 'Your Cbox subscription has been canceled',
            default => 'Your Cbox subscription has been updated',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscription-changed');
    }
}
