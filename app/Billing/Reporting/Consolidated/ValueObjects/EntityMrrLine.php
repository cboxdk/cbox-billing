<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * One selling entity's contribution to consolidated MRR: its native per-currency lines (a
 * subsidiary may bill in several currencies) and its total consolidated into the reporting
 * currency. `complete` is false when any of the entity's currencies had no rate — its
 * consolidated figure then covers only the convertible part, flagged honestly rather than
 * silently understated.
 */
readonly class EntityMrrLine
{
    /**
     * @param  list<CurrencyMrrLine>  $currencies
     */
    public function __construct(
        public string $entityId,
        public string $entityName,
        public array $currencies,
        public Money $consolidated,
        public bool $complete,
        public int $subscriptions,
    ) {}
}
