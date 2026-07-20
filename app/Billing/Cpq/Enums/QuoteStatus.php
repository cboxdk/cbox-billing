<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Enums;

/**
 * The lifecycle of a sales quote. This is the APP's sales-workflow status — distinct from the
 * engine's internal preview-quote {@see \Cbox\Billing\Quote\Enums\QuoteStatus} (draft/confirmed/
 * expired), which models a single confirmable price, not a rep-authored deal.
 *
 * The forward path is Draft → (PendingApproval →) Approved → Sent → Accepted, with Declined and
 * Expired as terminal off-ramps. A below-threshold quote skips PendingApproval (it is
 * auto-approved on submit); an above-threshold quote parks in PendingApproval until an approver
 * acts. Only an Approved quote can be Sent; only a Sent quote can be Accepted or Declined.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    /** A draft is still editable by the rep. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Waiting on the deal desk. */
    public function isPendingApproval(): bool
    {
        return $this === self::PendingApproval;
    }

    /** Approved (or auto-approved) and ready to send. */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** Out with the customer, awaiting their decision. */
    public function isSent(): bool
    {
        return $this === self::Sent;
    }

    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    /** Terminal — no further transition is possible. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Declined, self::Expired], true);
    }

    /** Whether the customer can still act on the order form (it is live and unexpired). */
    public function isOpenToCustomer(): bool
    {
        return $this === self::Sent;
    }

    /** A short human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
        };
    }

    /** The design-system pill tone for the status badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::PendingApproval => 'warning',
            self::Approved => 'info',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Declined => 'destructive',
            self::Expired => 'neutral',
        };
    }
}
