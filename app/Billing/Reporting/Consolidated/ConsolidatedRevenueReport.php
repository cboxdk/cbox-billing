<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated;

use App\Billing\Fx\FxConverter;
use App\Billing\Fx\FxRateRepository;
use App\Billing\Fx\ValueObjects\EffectiveRate;
use App\Billing\Reporting\Consolidated\ValueObjects\ConsolidatedMrr;
use App\Billing\Reporting\Consolidated\ValueObjects\ConsolidatedWaterfall;
use App\Billing\Reporting\Consolidated\ValueObjects\CurrencyMovementLine;
use App\Billing\Reporting\Consolidated\ValueObjects\CurrencyMrrLine;
use App\Billing\Reporting\Consolidated\ValueObjects\EntityMrrLine;
use App\Billing\Reporting\RevenueAnalytics;
use App\Billing\Reporting\RevenueMetrics;
use App\Billing\Seller\SellerCatalog;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\RetentionCalculator;
use Cbox\Billing\Reporting\ValueObjects\MrrWaterfall;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The consolidated multi-entity, multi-currency reporting read model — additive ON TOP of the
 * per-currency {@see RevenueMetrics}/{@see RevenueAnalytics}, which stay
 * untouched. Where those report one currency at a time, this normalizes the whole book to a
 * single reporting currency with real FX, so a multi-subsidiary/global seller sees one
 * consolidated MRR/ARR and how each entity and currency rolls into it.
 *
 * Formulae (all exact minor units; FX rates come only from the {@see FxRateRepository}, never
 * fabricated):
 *   consolidated MRR = Σ over currencies( native MRR → reporting currency at the effective rate )
 *   consolidated ARR = consolidated MRR × 12
 * A currency with no resolvable rate is reported as "unavailable" and excluded from the sum
 * rather than converted at an assumed rate.
 *
 * As-of policy: the MRR headline uses the live rate ("now"); a movement bridge uses the
 * documented `billing.reporting.fx.as_of` basis (default `period_end` — a closed period's rate
 * never moves, so the consolidation is reproducible). Every per-currency line shows the exact
 * date of the rate row applied.
 *
 * Conversion policy: each currency's AGGREGATED native MRR is converted once at that currency's
 * effective rate (one rate per currency, applied to the net exposure) and the results summed —
 * so the consolidated total equals the sum of the per-currency converted lines exactly, and the
 * per-currency table is the audit unit.
 */
readonly class ConsolidatedRevenueReport
{
    public function __construct(
        private MrrCalculator $mrr,
        private RetentionCalculator $retention,
        private FxConverter $converter,
        private FxRateRepository $rates,
        private SubscriptionEntityResolver $entities,
        private RevenueAnalytics $analytics,
        private SellerCatalog $sellers,
        private Config $config,
    ) {}

    /**
     * Consolidated MRR/ARR as of `$asOf` (default: now), optionally restricted to one selling
     * entity, with the per-currency and per-entity breakdowns.
     */
    public function mrr(?string $reportingCurrency = null, ?string $entityId = null, ?CarbonInterface $asOf = null): ConsolidatedMrr
    {
        $reporting = $this->reportingCurrency($reportingCurrency);
        $on = $asOf !== null ? CarbonImmutable::instance($asOf) : CarbonImmutable::now();
        $map = $this->entities->map();
        $default = $this->entities->defaultEntity();

        /** @var array<string, Money> $nativeByCurrency */
        $nativeByCurrency = [];
        /** @var array<string, int> $countByCurrency */
        $countByCurrency = [];
        /** @var array<string, array<string, Money>> $entityCurrencyNative */
        $entityCurrencyNative = [];
        /** @var array<string, array<string, int>> $entityCurrencyCount */
        $entityCurrencyCount = [];

        foreach ($this->subscriptions() as $subscription) {
            $status = $subscription->isPaused() ? SubscriptionStatus::Paused : $subscription->status;

            if (! $this->mrr->contributes($status)) {
                continue;
            }

            $entity = $map[$subscription->id] ?? $default;

            if ($entityId !== null && $entity !== $entityId) {
                continue;
            }

            $monthly = SubscriptionRevenue::monthly($subscription);
            $currency = $monthly->currency();

            $nativeByCurrency[$currency] = isset($nativeByCurrency[$currency])
                ? $nativeByCurrency[$currency]->plus($monthly)
                : $monthly;
            $countByCurrency[$currency] = ($countByCurrency[$currency] ?? 0) + 1;

            $entityCurrencyNative[$entity][$currency] = isset($entityCurrencyNative[$entity][$currency])
                ? $entityCurrencyNative[$entity][$currency]->plus($monthly)
                : $monthly;
            $entityCurrencyCount[$entity][$currency] = ($entityCurrencyCount[$entity][$currency] ?? 0) + 1;
        }

        ksort($nativeByCurrency);

        // One effective rate per currency, shared by the per-currency and per-entity tables so
        // every line reconciles to the same number.
        $rates = [];
        foreach ($nativeByCurrency as $currency => $native) {
            $rates[$currency] = $this->rates->effectiveRate($currency, $reporting, $on);
        }

        $total = Money::zero($reporting);
        $unavailable = [];
        $byCurrency = [];
        $subscriptions = 0;

        foreach ($nativeByCurrency as $currency => $native) {
            $count = $countByCurrency[$currency];
            $subscriptions += $count;
            [$converted, $rate] = $this->convertLine($native, $rates[$currency]);

            if ($converted === null) {
                $unavailable[] = $currency;
            } else {
                $total = $total->plus($converted);
            }

            $byCurrency[] = new CurrencyMrrLine($currency, $native, $count, $converted, $rate);
        }

        $byEntity = $this->entityLines($entityCurrencyNative, $entityCurrencyCount, $rates, $reporting);

        return new ConsolidatedMrr(
            reportingCurrency: $reporting,
            asOf: $on,
            mrr: $total,
            arr: $total->multipliedBy(12),
            byCurrency: $byCurrency,
            byEntity: $byEntity,
            unavailable: $unavailable,
            entityFilter: $entityId,
            subscriptions: $subscriptions,
        );
    }

    /**
     * The consolidated MRR-movement bridge across the whole book over `(start, end]`, FX-normalized
     * to the reporting currency at the period-end (or configured) rate, with consolidated NRR/GRR.
     * The entity filter is not applied here — the movement bridge is a book-wide consolidation
     * (see the analytics screen, where the entity filter scopes the MRR/ARR breakdown).
     */
    public function movement(?string $reportingCurrency, CarbonInterface $start, CarbonInterface $end, ?CarbonInterface $asOf = null): ConsolidatedWaterfall
    {
        $reporting = $this->reportingCurrency($reportingCurrency);
        $on = $asOf !== null ? CarbonImmutable::instance($asOf) : $this->asOfFor($end);
        $report = $this->analytics->movement(
            Carbon::instance($start),
            Carbon::instance($end),
        );

        $startSum = Money::zero($reporting);
        $newSum = Money::zero($reporting);
        $expansionSum = Money::zero($reporting);
        $contractionSum = Money::zero($reporting);
        $churnSum = Money::zero($reporting);
        $reactivationSum = Money::zero($reporting);

        $byCurrency = [];
        $unavailable = [];

        foreach ($report->waterfalls as $waterfall) {
            $rate = $this->rates->effectiveRate($waterfall->currency, $reporting, $on);

            if ($rate === null) {
                $unavailable[] = $waterfall->currency;
                $byCurrency[] = new CurrencyMovementLine($waterfall, null);

                continue;
            }

            $startSum = $startSum->plus($this->converter->applyRate($waterfall->startMrr, $rate));
            $newSum = $newSum->plus($this->converter->applyRate($waterfall->new, $rate));
            $expansionSum = $expansionSum->plus($this->converter->applyRate($waterfall->expansion, $rate));
            $contractionSum = $contractionSum->plus($this->converter->applyRate($waterfall->contraction, $rate));
            $churnSum = $churnSum->plus($this->converter->applyRate($waterfall->churn, $rate));
            $reactivationSum = $reactivationSum->plus($this->converter->applyRate($waterfall->reactivation, $rate));

            $byCurrency[] = new CurrencyMovementLine($waterfall, $rate);
        }

        // End is the identity over the converted components, so the consolidated bridge reconciles
        // exactly despite per-component rounding.
        $endSum = $startSum
            ->plus($newSum)
            ->plus($expansionSum)
            ->minus($contractionSum)
            ->minus($churnSum)
            ->plus($reactivationSum);

        $consolidated = new MrrWaterfall(
            $reporting,
            $startSum,
            $endSum,
            $newSum,
            $expansionSum,
            $contractionSum,
            $churnSum,
            $reactivationSum,
        );

        return new ConsolidatedWaterfall(
            reportingCurrency: $reporting,
            asOf: $on,
            waterfall: $consolidated,
            retention: $startSum->isPositive() ? $this->retention->fromWaterfall($consolidated) : null,
            byCurrency: $byCurrency,
            unavailable: $unavailable,
        );
    }

    /**
     * The selling entities present in the book (each with its display name), for the console's
     * entity filter. Always includes the default entity even when it has no subscriptions yet.
     *
     * @return list<array{id: string, name: string}>
     */
    public function entityOptions(): array
    {
        $ids = array_values(array_unique(array_merge(
            [$this->entities->defaultEntity()],
            array_values($this->entities->map()),
        )));
        sort($ids);

        return array_map(fn (string $id): array => [
            'id' => $id,
            'name' => $this->entities->entityName($id),
        ], $ids);
    }

    /**
     * The reporting currency: the explicit request, else `billing.reporting.currency`, else the
     * default selling entity's own currency (so a single-entity deployment needs no config).
     */
    public function reportingCurrency(?string $requested): string
    {
        if (is_string($requested) && $requested !== '') {
            return strtoupper($requested);
        }

        $configured = $this->config->get('billing.reporting.currency');

        if (is_string($configured) && $configured !== '') {
            return strtoupper($configured);
        }

        return $this->sellers->default()->defaultCurrency;
    }

    /**
     * The FX as-of date for a period bridge under `billing.reporting.fx.as_of`: `period_end`
     * (default — reproducible) or `today` (spot).
     */
    public function asOfFor(CarbonInterface $periodEnd): CarbonImmutable
    {
        $basis = $this->config->get('billing.reporting.fx.as_of', 'period_end');

        return $basis === 'today'
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($periodEnd);
    }

    /**
     * Convert one currency's native aggregate at its resolved rate.
     *
     * @return array{0: ?Money, 1: ?EffectiveRate}
     */
    private function convertLine(Money $native, ?EffectiveRate $rate): array
    {
        if ($rate === null) {
            return [null, null];
        }

        return [$this->converter->applyRate($native, $rate), $rate];
    }

    /**
     * Roll the per-entity native amounts up into consolidated entity lines, reusing the shared
     * per-currency rates. Ordered by consolidated amount descending (largest entity first).
     *
     * @param  array<string, array<string, Money>>  $entityCurrencyNative
     * @param  array<string, array<string, int>>  $entityCurrencyCount
     * @param  array<string, ?EffectiveRate>  $rates
     * @return list<EntityMrrLine>
     */
    private function entityLines(array $entityCurrencyNative, array $entityCurrencyCount, array $rates, string $reporting): array
    {
        $lines = [];

        foreach ($entityCurrencyNative as $entity => $currencies) {
            ksort($currencies);
            $entityTotal = Money::zero($reporting);
            $entitySubs = 0;
            $complete = true;
            $currencyLines = [];

            foreach ($currencies as $currency => $native) {
                $count = $entityCurrencyCount[$entity][$currency];
                $entitySubs += $count;
                [$converted, $rate] = $this->convertLine($native, $rates[$currency]);

                if ($converted === null) {
                    $complete = false;
                } else {
                    $entityTotal = $entityTotal->plus($converted);
                }

                $currencyLines[] = new CurrencyMrrLine($currency, $native, $count, $converted, $rate);
            }

            $lines[] = new EntityMrrLine(
                entityId: $entity,
                entityName: $this->entities->entityName($entity),
                currencies: $currencyLines,
                consolidated: $entityTotal,
                complete: $complete,
                subscriptions: $entitySubs,
            );
        }

        usort($lines, static fn (EntityMrrLine $a, EntityMrrLine $b): int => $b->consolidated->minor() <=> $a->consolidated->minor());

        return $lines;
    }

    /** @return Collection<int, Subscription> */
    private function subscriptions(): Collection
    {
        return Subscription::query()
            ->with(['organization', 'plan.prices.tiers'])
            ->get();
    }
}
