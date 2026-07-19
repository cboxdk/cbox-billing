<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Billing\Reporting\ValueObjects\MrrWaterfall;
use Cbox\Billing\Reporting\ValueObjects\RetentionRates;

/**
 * The MRR-movement bridge for the whole book, FX-normalized to one reporting currency: each
 * currency's native waterfall is converted component-by-component at that currency's period-end
 * rate and summed. The consolidated ending MRR is the accounting identity over the converted
 * components (start + new + expansion − contraction − churn + reactivation), so the bridge
 * reconciles exactly even though rounding happened per component.
 *
 * `retention` is the consolidated NRR/GRR from that bridge (null when the consolidated starting
 * MRR is zero). `byCurrency` is the per-currency audit (native bridge + the rate applied), and
 * `unavailable` names currencies with no period-end rate that were left out.
 */
readonly class ConsolidatedWaterfall
{
    /**
     * @param  list<CurrencyMovementLine>  $byCurrency
     * @param  list<string>  $unavailable
     */
    public function __construct(
        public string $reportingCurrency,
        public CarbonImmutable $asOf,
        public MrrWaterfall $waterfall,
        public ?RetentionRates $retention,
        public array $byCurrency,
        public array $unavailable,
    ) {}
}
