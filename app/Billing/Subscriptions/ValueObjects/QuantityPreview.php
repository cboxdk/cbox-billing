<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * The consequence of a seat-quantity change: the prorated {@see Money} due now for the
 * seat delta over the days still to run (a credit when seats drop), and the seat counts
 * it moves between. The same value backs the preview and the applied change, so what a
 * customer confirms is exactly what is charged (preview == charge).
 */
readonly class QuantityPreview
{
    public function __construct(
        public Money $charge,
        public int $fromSeats,
        public int $toSeats,
    ) {}

    /** A seat reduction nets a credit rather than a charge. */
    public function isCredit(): bool
    {
        return $this->charge->isNegative();
    }
}
