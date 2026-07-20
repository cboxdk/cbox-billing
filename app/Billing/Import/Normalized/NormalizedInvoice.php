<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;

/**
 * A historical, already-issued provider invoice mapped into the app's shape. It is imported as a
 * FAITHFUL RECORD — its original number, currency, subtotal/tax/total (minor units), status and
 * issue/period dates are preserved verbatim — NOT re-issued through the engine's invoicer (which
 * would assign a fresh legal number and recompute tax, destroying the historical record). Going-
 * forward invoices are issued through the engine as normal; only the closed back-catalogue is
 * migrated this way.
 *
 * `subscriptionSourceId` is null for an ad-hoc/one-off invoice with no subscription.
 *
 * @param  list<NormalizedInvoiceLine>  $lines
 */
readonly class NormalizedInvoice
{
    /**
     * @param  list<NormalizedInvoiceLine>  $lines
     */
    public function __construct(
        public string $sourceId,
        public string $customerSourceId,
        public ?string $subscriptionSourceId,
        public string $number,
        public ?string $currency,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public string $status,
        public ?CarbonImmutable $issuedAt,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public array $lines = [],
    ) {}
}
