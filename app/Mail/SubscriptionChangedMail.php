<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * Confirms a subscription change to the billing contact: a plan switch, or a cancellation
 * (immediate or scheduled for the period end). `changeType` is `plan_change`, `canceled` or
 * `cancel_scheduled`; the template keys its copy off the derived booleans. Rendered through
 * the branded, localized template system (see {@see TransactionalMailable}).
 */
class SubscriptionChangedMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $changeType,
        public string $planName,
        public ?string $previousPlanName = null,
        public ?string $effectiveAtLabel = null,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::SubscriptionChanged;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'change_type' => $this->changeType,
            'is_plan_change' => $this->changeType === 'plan_change',
            'is_canceled' => $this->changeType === 'canceled',
            'is_cancel_scheduled' => $this->changeType === 'cancel_scheduled',
            'plan_name' => $this->planName,
            'previous_plan_name' => $this->previousPlanName ?? '',
            'effective_at_label' => $this->effectiveAtLabel ?? '',
        ];
    }
}
