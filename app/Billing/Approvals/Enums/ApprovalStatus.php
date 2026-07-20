<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Enums;

use App\Models\ApprovalRequest;

/**
 * The lifecycle of a held {@see ApprovalRequest}. A sensitive action that trips
 * the policy is captured as `Pending`; a checker's decision moves it to `Approved` or
 * `Rejected`; an approved request's held action then runs exactly once → `Executed`. A
 * request may also lapse (`Expired`) or be withdrawn by its maker (`Canceled`). Only a
 * `Pending` request accepts a decision, and only an `Approved`-not-yet-executed request runs.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Expired = 'expired';
    case Canceled = 'canceled';

    /** Whether a checker may still record a decision (approve/reject) against the request. */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Whether the held action has already run — the terminal money-effect state. */
    public function isExecuted(): bool
    {
        return $this === self::Executed;
    }

    /** Whether the request is still open (awaiting the quorum or an execution). */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }

    /** A short human label for the console. */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The design-system pill tone for the status badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Executed => 'success',
            self::Rejected => 'destructive',
            self::Expired, self::Canceled => 'muted',
        };
    }
}
