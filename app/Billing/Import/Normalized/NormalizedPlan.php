<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;

/**
 * A provider plan (a recurring, billable catalog entry) mapped into the app's shape: the stable
 * provider id, the owning product's provider id (null when the provider models plans flat), the
 * natural `key`, a display name, the normalized cadence, and the raw provider cadence token kept
 * verbatim so an unsupported interval can be reported honestly.
 *
 * `interval` is null exactly when {@see NormalizedInterval::fromProvider()} did not recognise
 * {@see $rawInterval} as monthly/yearly — the importer surfaces that as a conflict rather than
 * guessing.
 */
readonly class NormalizedPlan
{
    public function __construct(
        public string $sourceId,
        public ?string $productSourceId,
        public string $key,
        public string $name,
        public ?NormalizedInterval $interval,
        public string $rawInterval,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
