<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

/**
 * A single variant as submitted from the console authoring form: its label, whether it is the
 * control, its non-negative traffic weight, and the pricing table it serves (null = the
 * experiment's base table, the control default). Parsed from the request array at the controller
 * edge into this typed shape before it reaches {@see App\Billing\Experiments\ExperimentAuthoring}.
 */
readonly class VariantDraft
{
    public function __construct(
        public string $label,
        public bool $isControl,
        public int $weight,
        public ?int $servedPricingTableId,
    ) {}
}
