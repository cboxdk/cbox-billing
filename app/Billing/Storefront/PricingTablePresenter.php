<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Billing\Experiments\ValueObjects\ExperimentAttribution;
use App\Billing\Notifications\Branding\BrandingResolver;
use App\Billing\Storefront\Contracts\BuildsCheckoutLinks;
use App\Billing\Storefront\ValueObjects\FeatureCell;
use App\Billing\Storefront\ValueObjects\FeatureRow;
use App\Billing\Storefront\ValueObjects\PriceOffer;
use App\Billing\Storefront\ValueObjects\PricingColumn;
use App\Billing\Storefront\ValueObjects\RenderedPricingTable;
use App\Billing\Support\MoneyFormatter;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PricingTable;
use App\Models\PricingTableFeature;
use App\Models\PricingTablePlan;

/**
 * The pricing-table read model (#57): projects a {@see PricingTable} definition + the live
 * catalog into a fully-resolved {@see RenderedPricingTable} the public page, the embeddable
 * frame, and the console live-preview all render — with EVERY currency/interval permutation
 * precomputed, so the toggles swap values entirely client-side and the page stays
 * self-contained.
 *
 * Everything is catalog truth: prices are the stored minor amounts formatted through the same
 * {@see MoneyFormatter} the console uses, feature cells are read from each column plan's
 * {@see PlanFeature} grant, and branding is resolved through the shared
 * {@see BrandingResolver}. Deny-by-default throughout — a plan not priced in a presented
 * currency yields an unavailable offer (a struck "—"), never a fabricated amount, and a column
 * priced in none of the presented currencies is dropped.
 */
readonly class PricingTablePresenter
{
    private const string INTERVAL_MONTH = 'month';

    private const string INTERVAL_YEAR = 'year';

    public function __construct(
        private BrandingResolver $branding,
        private BuildsCheckoutLinks $links,
    ) {}

    /**
     * @param  ?ExperimentAttribution  $attribution  When the table is served under a running A/B
     *                                               experiment, the assigned variant's attribution
     *                                               — threaded onto every CTA deep-link so the
     *                                               conversion traces back to the variant.
     */
    public function present(PricingTable $table, ?ExperimentAttribution $attribution = null): RenderedPricingTable
    {
        $table->loadMissing([
            'columns.plan.prices',
            'columns.plan.entitlements.meter',
            'columns.plan.features',
            'columns.annualPlan.prices',
            'featureRows.feature',
        ]);

        $currencies = $this->currencies($table);
        $hasAnnual = $this->hasAnnual($table);
        $intervals = $hasAnnual ? [self::INTERVAL_MONTH, self::INTERVAL_YEAR] : [self::INTERVAL_MONTH];

        $columns = [];

        foreach ($table->columns as $column) {
            $rendered = $this->column($table, $column, $currencies, $intervals, $attribution);

            if ($rendered !== null) {
                $columns[] = $rendered;
            }
        }

        $featureRows = array_values(array_map(
            fn (PricingTableFeature $row): FeatureRow => $this->featureRow($row, $table->columns),
            $table->featureRows->all(),
        ));

        $defaultCurrency = $this->defaultCurrency($table, $currencies);

        return new RenderedPricingTable(
            key: $table->key,
            name: $table->name,
            branding: $this->branding->forSeller($table->seller_entity_id),
            currencies: $currencies,
            defaultCurrency: $defaultCurrency,
            intervals: $intervals,
            defaultInterval: self::INTERVAL_MONTH,
            hasIntervalToggle: $table->interval_toggle && $hasAnnual,
            ctaLabel: $this->ctaLabel($table),
            columns: $columns,
            featureRows: $featureRows,
        );
    }

    /**
     * @param  list<string>  $currencies
     * @param  list<string>  $intervals
     */
    private function column(PricingTable $table, PricingTablePlan $column, array $currencies, array $intervals, ?ExperimentAttribution $attribution): ?PricingColumn
    {
        $plan = $column->plan;

        if (! $plan instanceof Plan) {
            return null;
        }

        $offers = [];
        $anyAvailable = false;

        foreach ($currencies as $currency) {
            foreach ($intervals as $interval) {
                $offer = $this->offer($table, $column, $currency, $interval, $attribution);
                $offers[$currency][$interval] = $offer;
                $anyAvailable = $anyAvailable || $offer->available;
            }
        }

        // A column priced in none of the presented currencies is dropped (deny-by-default) —
        // never shown as an all-"—" column.
        if (! $anyAvailable) {
            return null;
        }

        return new PricingColumn(
            planKey: $plan->key,
            name: $plan->name,
            featured: $column->featured,
            badge: $this->trimmedOrNull($column->badge),
            highlight: $this->trimmedOrNull($column->highlight),
            allowances: $this->allowances($plan),
            offers: $offers,
        );
    }

    private function offer(PricingTable $table, PricingTablePlan $column, string $currency, string $interval, ?ExperimentAttribution $attribution): PriceOffer
    {
        $per = $interval === self::INTERVAL_YEAR ? '/yr' : '/mo';
        $plan = $interval === self::INTERVAL_YEAR ? $column->annualPlan : $column->plan;

        if (! $plan instanceof Plan) {
            return PriceOffer::unavailable($currency, $interval, $per);
        }

        $price = $plan->prices->firstWhere('currency', $currency);

        if (! $price instanceof PlanPrice) {
            return PriceOffer::unavailable($currency, $interval, $per);
        }

        $minor = $price->price_minor;

        return new PriceOffer(
            currency: $currency,
            interval: $interval,
            available: true,
            minor: $minor,
            formatted: MoneyFormatter::minor($minor, $currency),
            per: $per,
            ctaUrl: $this->links->build(
                $table->cta_url_template,
                $plan->key,
                $currency,
                $interval,
                $minor,
                $attribution?->toQueryParams() ?? [],
            ),
        );
    }

    /**
     * The plan's included metered allowances, as the card's highlight bullets. Deny-by-default:
     * a disabled entitlement is omitted (it is not included), mirroring the pricing partial.
     *
     * @return list<string>
     */
    private function allowances(Plan $plan): array
    {
        $lines = [];

        foreach ($plan->entitlements as $entitlement) {
            if (! $entitlement->enabled) {
                continue;
            }

            $meter = $entitlement->meter;
            $name = $meter !== null ? $meter->name : 'Usage';
            $unit = $meter !== null && $meter->unit !== '' ? $meter->unit : strtolower($name);

            $lines[] = $entitlement->unlimited
                ? 'Unlimited '.strtolower($name)
                : number_format((float) $entitlement->allowance).' '.$unit;
        }

        return $lines;
    }

    /**
     * @param  iterable<int, PricingTablePlan>  $columns
     */
    private function featureRow(PricingTableFeature $row, iterable $columns): FeatureRow
    {
        $feature = $row->feature;
        $cells = [];

        if ($feature instanceof Feature) {
            foreach ($columns as $column) {
                $plan = $column->plan;

                if ($plan instanceof Plan) {
                    $cells[$plan->key] = $this->cell($feature, $plan);
                }
            }
        }

        return new FeatureRow(
            key: $feature instanceof Feature ? $feature->key : 'unknown',
            name: $feature instanceof Feature ? $feature->name : 'Unknown feature',
            description: $feature instanceof Feature ? $this->trimmedOrNull($feature->description) : null,
            cells: $cells,
        );
    }

    private function cell(Feature $feature, Plan $plan): FeatureCell
    {
        $grant = $plan->features->first(
            static fn (PlanFeature $planFeature): bool => $planFeature->feature_id === $feature->id && $planFeature->enabled,
        );

        if (! $grant instanceof PlanFeature) {
            return FeatureCell::absent();
        }

        $value = $feature->castValue($grant->value);

        return new FeatureCell(true, $value === null ? null : (string) $value);
    }

    /**
     * The currencies the table presents: its configured list filtered to those at least one
     * column plan is actually priced in, else the sorted union of all its columns' currencies
     * (deny-by-default — never a currency no plan carries).
     *
     * @return list<string>
     */
    private function currencies(PricingTable $table): array
    {
        $priced = $this->pricedCurrencies($table);
        $configured = $table->currencies ?? [];

        if ($configured !== []) {
            $filtered = array_values(array_filter(
                array_map(static fn (string $currency): string => strtoupper($currency), $configured),
                static fn (string $currency): bool => in_array($currency, $priced, true),
            ));

            if ($filtered !== []) {
                return $filtered;
            }
        }

        return $priced;
    }

    /**
     * The sorted union of every currency any column (monthly or annual plan) is priced in.
     *
     * @return list<string>
     */
    private function pricedCurrencies(PricingTable $table): array
    {
        $seen = [];

        foreach ($table->columns as $column) {
            foreach ([$column->plan, $column->annualPlan] as $plan) {
                if ($plan instanceof Plan) {
                    foreach ($plan->prices as $price) {
                        $seen[$price->currency] = true;
                    }
                }
            }
        }

        $currencies = array_keys($seen);
        sort($currencies);

        return $currencies;
    }

    /**
     * @param  list<string>  $currencies
     */
    private function defaultCurrency(PricingTable $table, array $currencies): string
    {
        $preferred = $table->default_currency !== null ? strtoupper($table->default_currency) : null;

        if ($preferred !== null && in_array($preferred, $currencies, true)) {
            return $preferred;
        }

        return $currencies[0] ?? ($preferred ?? '');
    }

    private function hasAnnual(PricingTable $table): bool
    {
        foreach ($table->columns as $column) {
            $annual = $column->annualPlan;

            if ($annual instanceof Plan && $annual->prices->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    private function ctaLabel(PricingTable $table): string
    {
        return $this->trimmedOrNull($table->cta_label) ?? 'Get started';
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
