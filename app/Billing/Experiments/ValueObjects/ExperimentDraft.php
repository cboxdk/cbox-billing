<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

use App\Billing\Experiments\Enums\ExperimentMetric;

/**
 * A whole experiment as submitted from the console authoring form — the definition plus its
 * ordered variants. Parsed from the request at the controller edge into this typed shape;
 * {@see App\Billing\Experiments\ExperimentAuthoring} validates and persists it.
 */
readonly class ExperimentDraft
{
    /**
     * @param  list<VariantDraft>  $variants
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $hypothesis,
        public ExperimentMetric $primaryMetric,
        public int $pricingTableId,
        public array $variants,
    ) {}
}
