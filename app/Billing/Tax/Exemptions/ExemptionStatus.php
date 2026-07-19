<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

/**
 * The lifecycle state of an exemption certificate. Deny-by-default: only {@see Verified}
 * (and non-expired) exempts. `pending` is the as-uploaded state awaiting operator review;
 * `rejected` is an operator's refusal; `expired` is the housekeeping state the scheduled
 * `tax:expire-certificates` command flips past-expiry certificates into.
 */
enum ExemptionStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /** The pill variant the console renders this status with. */
    public function pill(): string
    {
        return match ($this) {
            self::Verified => 'success',
            self::Pending => 'warning',
            self::Rejected => 'destructive',
            self::Expired => 'muted',
        };
    }

    /** Whether an operator can still act (verify/reject) on a certificate in this state. */
    public function isReviewable(): bool
    {
        return $this === self::Pending;
    }
}
