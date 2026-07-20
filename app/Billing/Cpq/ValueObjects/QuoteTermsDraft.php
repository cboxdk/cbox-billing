<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

/**
 * The authored contract terms of a quote: the contract length (`termCount`/`termUnit`), the
 * recurring `billingInterval`, an optional `startDate`, an optional per-period
 * `minimumCommitmentMinor` floor, and an optional predetermined `ramp` (a list of
 * {from_period_index, amount_minor} steps → engine RampSchedule).
 *
 * @property list<array{from_period_index: int, amount_minor: int}>|null $ramp
 */
readonly class QuoteTermsDraft
{
    /**
     * @param  list<array{from_period_index: int, amount_minor: int}>|null  $ramp
     */
    public function __construct(
        public int $termCount,
        public string $termUnit,
        public string $billingInterval,
        public ?string $startDate,
        public ?int $minimumCommitmentMinor,
        public ?array $ramp,
    ) {}
}
