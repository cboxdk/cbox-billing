<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The trial-ending reminder, sent ahead of a trial's conversion so the customer knows the
 * free trial is about to become a paying subscription (and its first charge is coming).
 * Rendered through the branded, localized template system (see {@see TransactionalMailable}).
 */
class TrialEndingMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $endsAtLabel,
        public string $amountFormatted,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::TrialEnding;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'plan_name' => $this->planName,
            'ends_at_label' => $this->endsAtLabel,
            'amount_formatted' => $this->amountFormatted,
        ];
    }
}
