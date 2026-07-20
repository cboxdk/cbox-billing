<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * One priced quote line, resolved through the engine: its base net (before any discount), the
 * total discount applied to it (the per-line discount plus its proportional share of an
 * order-level coupon), the final net, and the tax-aware net/tax/gross the engine computed for it.
 * `recurring` marks whether the line is part of the ongoing subscription or a one-time charge.
 */
readonly class ComputedLine
{
    public function __construct(
        public string $label,
        public int $quantity,
        public bool $recurring,
        public Money $baseNet,
        public Money $discount,
        public Money $net,
        public Money $tax,
        public Money $gross,
        public string $taxNote,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discount->isPositive();
    }
}
