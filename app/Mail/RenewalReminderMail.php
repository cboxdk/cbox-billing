<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The renewal reminder, sent ahead of a subscription's term renewal so the customer knows the
 * next period (and its charge) is coming. Rendered through the branded, localized template
 * system (see {@see TransactionalMailable}).
 */
class RenewalReminderMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $renewsAtLabel,
        public string $amountFormatted,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::RenewalReminder;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'plan_name' => $this->planName,
            'renews_at_label' => $this->renewsAtLabel,
            'amount_formatted' => $this->amountFormatted,
        ];
    }
}
