<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;

/**
 * A provider price mapped into the app's shape: the recurring amount in MINOR units (the adapter
 * has already converted the provider's own unit convention — Stripe/Chargebee integer minor
 * units are passed through, Recurly's decimal major-unit strings are multiplied up), the ISO
 * currency, and the provider plan it prices.
 *
 * `currency` is null when the provider record carried none — the importer flags that as a
 * missing-currency conflict rather than inventing one; `amountMinor` is always a non-negative
 * integer minor amount by the time it reaches here.
 */
readonly class NormalizedPrice
{
    public function __construct(
        public string $sourceId,
        public string $planSourceId,
        public ?string $currency,
        public int $amountMinor,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
