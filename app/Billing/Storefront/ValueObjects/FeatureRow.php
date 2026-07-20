<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

/**
 * One row of the feature comparison matrix: the catalog feature being compared, and the
 * per-column {@see FeatureCell} answers keyed by plan key.
 */
readonly class FeatureRow
{
    /**
     * @param  array<string, FeatureCell>  $cells  keyed by plan key
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public array $cells,
    ) {}

    /** The cell for a plan column, or the not-granted default when the column has none. */
    public function cell(string $planKey): FeatureCell
    {
        return $this->cells[$planKey] ?? FeatureCell::absent();
    }
}
