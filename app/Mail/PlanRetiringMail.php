<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The plan-retiring reminder (ADR-0016), sent ahead of a plan's sunset cutoff so an affected
 * subscriber can choose a new plan before their next renewal. Carries the plan name, the
 * cutoff date, the renewal-due deadline, and — when configured — the default plan they fall
 * to. Rendered through the branded, localized template system (see {@see TransactionalMailable}).
 */
class PlanRetiringMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $planName,
        public string $retiresAtLabel,
        public string $renewalDueLabel,
        public ?string $defaultSuccessorName,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::PlanRetiring;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'plan_name' => $this->planName,
            'retires_at_label' => $this->retiresAtLabel,
            'renewal_due_label' => $this->renewalDueLabel,
            'default_successor_name' => $this->defaultSuccessorName ?? '',
        ];
    }
}
