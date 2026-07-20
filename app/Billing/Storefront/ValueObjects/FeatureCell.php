<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * One cell of the feature comparison matrix: whether a given plan column grants a given
 * feature, and — for a config feature — its typed value presented as a string (e.g. "50").
 * A boolean grant carries no value; a not-granted cell is `granted: false, value: null`.
 * Read from the column plan's {@see App\Models\PlanFeature} grant (deny-by-default).
 */
readonly class FeatureCell
{
    public function __construct(
        public bool $granted,
        public ?string $value,
    ) {}

    /** The not-granted cell (deny-by-default). */
    public static function absent(): self
    {
        return new self(false, null);
    }
}
