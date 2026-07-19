<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\ValueObjects;

/**
 * One operator-authored line of an ad-hoc invoice (Wave 3): a description, a quantity,
 * and a per-unit NET amount in minor units. The service prices it through the engine
 * quote builder (tax for the buyer's place of supply), so the operator never hand-rolls
 * tax or totals.
 */
readonly class ManualLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitMinor,
    ) {}
}
