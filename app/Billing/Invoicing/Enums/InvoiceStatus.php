<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\Enums;

use App\Models\Invoice;

/**
 * The lifecycle status of an {@see Invoice}. A newly-issued document is `Draft`, becomes
 * `Open` once finalized (a legal number drawn), then `Paid` on settlement — or `Void` if
 * cancelled before payment, `Uncollectible` when written off, or `Refunded` once fully
 * reversed by a credit note. Backs the `status` column and is the single source of truth for
 * "which statuses may be voided / refunded" — the guard the lifecycle service enforces, the
 * affordance the console shows, and the tone the status pill renders all read it from here,
 * so the vocabulary can never drift across the app.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';
    case Refunded = 'refunded';

    /** Whether this invoice has been settled — the terminal money-in state. */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Whether a refund may reverse this invoice: an issued, non-voided/non-draft document
     * (open, paid or uncollectible). The one definition of the refundable rule the invoice
     * lifecycle service guards on and the console shows the refund affordance for.
     */
    public function isRefundable(): bool
    {
        return in_array($this, [self::Open, self::Paid, self::Uncollectible], true);
    }

    /** Whether this invoice may be voided: issued but not yet settled (open or uncollectible). */
    public function isVoidable(): bool
    {
        return in_array($this, [self::Open, self::Uncollectible], true);
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
            self::Paid => 'success',
            self::Open, self::Uncollectible => 'warning',
            self::Draft, self::Void, self::Refunded => 'muted',
        };
    }
}
