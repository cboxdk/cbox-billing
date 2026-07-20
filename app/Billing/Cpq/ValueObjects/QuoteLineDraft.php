<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

use App\Billing\Cpq\Enums\QuoteDiscountKind;
use App\Billing\Cpq\Enums\QuoteLineType;

/**
 * An authored quote line: a catalog plan line (plan + quantity, recurring) or a custom one-off
 * (description + unit amount). An optional per-line discount reduces the line net before tax.
 */
readonly class QuoteLineDraft
{
    public function __construct(
        public QuoteLineType $type,
        public ?int $planId,
        public ?string $description,
        public int $quantity,
        public ?int $unitAmountMinor,
        public ?QuoteDiscountKind $discountKind,
        public ?int $discountValue,
        public bool $recurring,
    ) {}
}
