<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;

/**
 * A provider product mapped into the app's shape: the stable provider id (the idempotency key),
 * a slug `key` (the app's natural key — derived from the provider id when the provider has no
 * separate key), a display name and description, and the original creation timestamp so catalog
 * history is preserved.
 */
readonly class NormalizedProduct
{
    public function __construct(
        public string $sourceId,
        public string $key,
        public string $name,
        public ?string $description,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
