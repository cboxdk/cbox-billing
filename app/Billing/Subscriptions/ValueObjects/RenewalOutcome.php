<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\ValueObjects;

use App\Models\Invoice;

/**
 * The result of running the scheduled renewal over a single subscription: whether its base
 * period advanced onto a new cycle, whether a due cancellation ended it, how many add-on
 * allotments were (re)granted, and the renewal invoice issued for the new period (when one
 * was). A `skipped` outcome is a paused/canceled subscription the run left untouched.
 *
 * The service always grants any recurring allotment slice that has vested by `now` — the
 * boundary advance, add-on renewal, and invoice are the additional effects that fire only
 * when a period actually rolls over.
 */
readonly class RenewalOutcome
{
    public function __construct(
        public bool $skipped,
        public bool $baseRenewed,
        public bool $canceled,
        public int $addOnsRenewed,
        public ?Invoice $invoice = null,
    ) {}

    /** A paused or already-canceled subscription: deny-by-default, nothing granted. */
    public static function skipped(): self
    {
        return new self(skipped: true, baseRenewed: false, canceled: false, addOnsRenewed: 0);
    }

    /** A subscription whose due end-of-period cancellation was enacted instead of renewed. */
    public static function canceled(): self
    {
        return new self(skipped: false, baseRenewed: false, canceled: true, addOnsRenewed: 0);
    }

    /**
     * A processed subscription: `baseRenewed` when its period rolled over (with the issued
     * `invoice`), plus however many add-on allotments were granted on their own boundaries.
     */
    public static function processed(bool $baseRenewed, int $addOnsRenewed, ?Invoice $invoice): self
    {
        return new self(
            skipped: false,
            baseRenewed: $baseRenewed,
            canceled: false,
            addOnsRenewed: $addOnsRenewed,
            invoice: $invoice,
        );
    }

    /** Did this run change anything for the subscription (a grant, a renewal, a cancel)? */
    public function didWork(): bool
    {
        return $this->baseRenewed || $this->canceled || $this->addOnsRenewed > 0;
    }
}
