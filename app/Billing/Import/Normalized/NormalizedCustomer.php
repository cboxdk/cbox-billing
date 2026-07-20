<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * A provider customer mapped into the app's {@see Organization} shape: the stable
 * provider id, a display name, a billing email, the chosen billing currency (pinned on the org),
 * the place-of-supply country (ISO-3166 alpha-2, for tax), an optional tax id, and the original
 * signup timestamp — preserved as the org's `created_at` so cohort/MRR history is faithful.
 *
 * Every nullable field stays null rather than being fabricated: a customer with no country is
 * imported tax-pending, exactly as a natively-created one would be.
 */
readonly class NormalizedCustomer
{
    public function __construct(
        public string $sourceId,
        public string $name,
        public ?string $email,
        public ?string $currency,
        public ?string $country,
        public ?string $taxId,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
