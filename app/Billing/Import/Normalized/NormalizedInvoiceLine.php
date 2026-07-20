<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

/**
 * One line of a historical invoice: a description, a quantity, the per-unit minor amount and the
 * line's minor total. Amounts are already in minor units (the adapter converted the provider's
 * unit convention upstream).
 */
readonly class NormalizedInvoiceLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitAmountMinor,
        public int $amountMinor,
    ) {}
}
