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
 * The plan-retiring reminder (ADR-0016), sent ahead of a plan's sunset cutoff so an
 * affected subscriber knows their plan is being retired and can choose a new plan before
 * their next renewal. Carries the plan name, the cutoff date, the renewal-due deadline, and
 * — when configured — the default plan they fall to if they do nothing. Queued once per
 * subscription per retirement window from the migration pass.
 */
class PlanRetiringMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $retiresAtLabel,
        public string $renewalDueLabel,
        public ?string $defaultSuccessorName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->planName.' is being retired — choose your new plan');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.plan-retiring');
    }
}
