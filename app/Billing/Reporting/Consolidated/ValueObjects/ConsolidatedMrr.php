<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Billing\Money\Money;

/**
 * The consolidated recurring-revenue headline for a multi-entity, multi-currency book,
 * normalized to a single reporting currency at the rates effective as of `asOf`:
 *
 *   consolidated MRR = Σ over currencies( native MRR in that currency → reporting currency )
 *   consolidated ARR = consolidated MRR × 12
 *
 * Only currencies with a resolvable rate are summed; `unavailable` names the rest so the total
 * is never quietly understated by a fabricated or assumed rate. `byCurrency` is the auditable
 * breakdown (native → converted at the shown rate + date); `byEntity` is the same rolled up per
 * selling entity. `entityFilter` records the single entity this view was restricted to, or null
 * for the whole book.
 */
readonly class ConsolidatedMrr
{
    /**
     * @param  list<CurrencyMrrLine>  $byCurrency
     * @param  list<EntityMrrLine>  $byEntity
     * @param  list<string>  $unavailable
     */
    public function __construct(
        public string $reportingCurrency,
        public CarbonImmutable $asOf,
        public Money $mrr,
        public Money $arr,
        public array $byCurrency,
        public array $byEntity,
        public array $unavailable,
        public ?string $entityFilter,
        public int $subscriptions,
    ) {}

    /** Whether every native currency in the book converted (nothing shown as unavailable). */
    public function complete(): bool
    {
        return $this->unavailable === [];
    }
}
