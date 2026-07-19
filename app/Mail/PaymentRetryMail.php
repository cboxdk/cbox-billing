<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The smart-retry payment-failed notice, sent at each step of a failed renewal charge's
 * retry schedule: the initial failure (`attempt` 0), each failed retry, and — when
 * `exhausted` — the final give-up notice. Rendered through the branded, localized template
 * system (see {@see TransactionalMailable}).
 */
class PaymentRetryMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $invoiceNumber,
        public string $amountFormatted,
        public int $attempt,
        public int $maxAttempts,
        public ?string $nextAttemptLabel = null,
        public bool $exhausted = false,
        public string $declineCategory = 'unknown',
        public bool $needsAction = false,
        public bool $requiresNewMethod = false,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::PaymentRetry;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'invoice_number' => $this->invoiceNumber,
            'amount_formatted' => $this->amountFormatted,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'next_attempt_label' => $this->nextAttemptLabel ?? '',
            'exhausted' => $this->exhausted,
            // The decline category selects the message: `needs_action` sends an authenticate
            // link; a hard decline (`requires_new_method`) asks for a new card up front.
            'decline_category' => $this->declineCategory,
            'needs_action' => $this->needsAction,
            'requires_new_method' => $this->requiresNewMethod,
        ];
    }
}
