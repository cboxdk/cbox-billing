<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The dunning notice, sent to the billing contact at each dunning step for a past-due
 * account. The `suspended` flag drives the copy (a warning reminder vs a suspension notice)
 * so the customer is never suspended un-warned. Rendered through the branded, localized
 * template system (see {@see TransactionalMailable}).
 */
class PaymentFailedMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $amountDueFormatted,
        public bool $suspended,
        public ?string $oldestDueLabel = null,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::PaymentFailed;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'amount_due_formatted' => $this->amountDueFormatted,
            'suspended' => $this->suspended,
            'oldest_due_label' => $this->oldestDueLabel ?? '',
        ];
    }
}
