<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The consequence of a seat-quantity change: the prorated seat-delta {@see Money} over the
 * days still to run, and the seat counts it moves between. The same value backs the preview
 * and the applied change, so what a customer confirms is exactly what is charged (preview ==
 * charge).
 *
 * Two figures, distinct because the apply path taxes the charge:
 *  - `charge` — the signed NET proration the engine computed (negative = a credit on a seat
 *    reduction). This is what the collector feeds to the invoicer, and it must NOT be taxed
 *    again by a display.
 *  - `grossDueNow` — the tax-aware GROSS actually collected from the card (the net taxed for
 *    the org's place of supply), or zero on a credit (a reduction owes nothing now). This is
 *    the "Due now" a preview shows, so it equals the amount the apply charges.
 */
readonly class QuantityPreview
{
    public function __construct(
        public Money $charge,
        public int $fromSeats,
        public int $toSeats,
        public Money $grossDueNow,
    ) {}

    /** A seat reduction nets a credit rather than a charge. */
    public function isCredit(): bool
    {
        return $this->charge->isNegative();
    }
}
