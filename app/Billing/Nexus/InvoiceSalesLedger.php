<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Fx\FxConverter;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Seller\SellerCatalog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SellerExternalSales;
use Cbox\Billing\Money\Money;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusThresholdSource;
use Cbox\Nexus\Contracts\SalesLedger;
use Cbox\Nexus\ValueObjects\SellerActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The {@see SalesLedger} for the nexus engine: the default selling entity's CUMULATIVE
 * US sales into a state over the common "previous or current calendar year" window,
 * combining every channel that counts toward the threshold —
 *
 *  1. this platform's finalized invoices (open/paid) to buyers whose place of supply
 *     ({@see Organization::$billing_subdivision}) is the state, in ANY currency —
 *     non-USD is converted to USD via {@see FxConverter} so foreign-currency sales are
 *     not silently dropped; and
 *  2. operator-declared sales through OTHER channels ({@see SellerExternalSales} —
 *     marketplaces, other systems), which count toward the same state threshold.
 *
 * Queries run through the Eloquent models, so the environment (plane) scope applies
 * automatically; sales are isolated to the current default seller. Transaction counts
 * are currency-independent and always complete; a foreign-currency amount with no FX
 * rate contributes its transactions but not its (unvaluable) dollars — an honest
 * under-count of dollars rather than a fabricated figure.
 *
 * Measurement precision caveat: economic-nexus measurement periods vary by state
 * (previous / current / rolling twelve months); the SalesLedger contract carries no
 * period, so this uses the dominant previous-or-current-calendar-year window.
 */
readonly class InvoiceSalesLedger implements SalesLedger
{
    public function __construct(
        private SellerCatalog $sellers,
        private FxConverter $fx,
        private NexusThresholdSource $thresholds,
    ) {}

    public function activityFor(SubdivisionCode $state): ?SellerActivity
    {
        if ($state->country->value !== 'US') {
            return null;
        }

        $now = Carbon::now();

        // "Previous OR current calendar year" means each year is tested SEPARATELY — a threshold
        // is crossed when either year exceeds it. Summing the two, which is what a single window
        // starting at last January does, roughly DOUBLES the measured figure: a seller at $60k a
        // year into California reads $120k and registers, files and collects tax in a state where
        // it has no obligation.
        $currentStart = $now->copy()->startOfYear();
        $previousStart = $currentStart->copy()->subYear();
        $nextStart = $currentStart->copy()->addYear();

        // Each window is a whole calendar year, half-open [start, next). The current year runs to
        // NEXT January rather than to `now`, so an invoice issued this instant is inside it.
        [$currentDollars, $currentTransactions] = $this->windowActivity($state, $currentStart, $nextStart, $now);
        [$previousDollars, $previousTransactions] = $this->windowActivity($state, $previousStart, $currentStart, $now);

        if ($currentDollars === 0 && $currentTransactions === 0 && $previousDollars === 0 && $previousTransactions === 0) {
            return null;
        }

        // REPORT ONE YEAR'S PAIR, NEVER A MIXTURE. Maxing the two metrics independently — which
        // this did — invents a year that never happened: $600k/40 transactions followed by
        // $80k/150 reads as $600k AND 150. For an `and` state (NY at $500k+100, CT at $100k+200)
        // both legs then appear satisfied although neither year crossed both, and the seller is
        // told to register, collect and remit somewhere it has no obligation.
        //
        // The threshold is consulted here — the same source the engine uses — so the stronger
        // year can be chosen by the state's own rule: `progress()` already takes the max leg for
        // an `or` state and the min leg for an `and` state, which is exactly the comparison
        // wanted. Ties and an unresolvable threshold fall back to the higher dollars, still as a
        // whole pair.
        [$salesDollars, $transactions] = $this->strongerYear(
            $state,
            [$currentDollars, $currentTransactions],
            [$previousDollars, $previousTransactions],
        );

        return new SellerActivity(
            salesDollars: $salesDollars,
            transactions: $transactions,
            periodStart: $previousStart->toDateTimeImmutable(),
            periodEnd: $now->toDateTimeImmutable(),
        );
    }

    /**
     * The stronger of two calendar years, kept whole.
     *
     * "Stronger" is the state's own question, so it is asked of the state's own threshold:
     * {@see EconomicNexusThreshold::progress()} returns the nearer leg for an `or` state and the
     * further leg for an `and` state, so the year with the greater progress is the year most
     * likely to establish nexus under that state's rule. With no resolvable threshold there is
     * no rule to apply, so the higher-dollar year is reported — still a real year, never a
     * synthesised one.
     *
     * @param  array{0: int, 1: int}  $current
     * @param  array{0: int, 1: int}  $previous
     * @return array{0: int, 1: int}
     */
    private function strongerYear(SubdivisionCode $state, array $current, array $previous): array
    {
        $threshold = $this->thresholds->thresholdFor($state);

        if ($threshold === null) {
            return $current[0] >= $previous[0] ? $current : $previous;
        }

        $currentProgress = $threshold->progress($current[0], $current[1]);
        $previousProgress = $threshold->progress($previous[0], $previous[1]);

        if ($currentProgress === $previousProgress) {
            return $current[0] >= $previous[0] ? $current : $previous;
        }

        return $currentProgress > $previousProgress ? $current : $previous;
    }

    /**
     * Platform plus external-channel activity for one calendar-year window.
     *
     * @return array{0: int, 1: int} [dollars, transactions]
     */
    private function windowActivity(SubdivisionCode $state, Carbon $from, Carbon $until, Carbon $asOf): array
    {
        [$platformDollars, $platformTransactions] = $this->platformActivity($state, $from, $until, $asOf);
        [$externalDollars, $externalTransactions] = $this->externalActivity($state, $from, $until, $asOf);

        return [$platformDollars + $externalDollars, $platformTransactions + $externalTransactions];
    }

    /**
     * This platform's invoiced sales into the state over the window, across every
     * currency (non-USD converted to USD as of the reporting date).
     *
     * @return array{0: int, 1: int} [dollars, transactions]
     */
    private function platformActivity(SubdivisionCode $state, Carbon $windowStart, Carbon $windowEnd, Carbon $asOf): array
    {
        // Buyer place of supply lives on the organization (env-scoped). Resolve the
        // matching orgs first, then aggregate their invoices — keeps the columns on
        // their own typed model and the environment scope applied throughout.
        $organizationIds = Organization::query()
            ->where('billing_country', 'US')
            ->where('billing_subdivision', $state->value)
            ->pluck('id')
            ->all();

        if ($organizationIds === []) {
            return [0, 0];
        }

        $base = Invoice::query()
            ->where('seller', $this->sellers->default()->id)
            ->whereIn('status', [InvoiceStatus::Open, InvoiceStatus::Paid])
            ->where('issued_at', '>=', $windowStart)
            ->where('issued_at', '<', $windowEnd)
            ->whereIn('organization_id', $organizationIds);

        $dollars = 0;
        $transactions = 0;

        foreach ((clone $base)->distinct()->pluck('currency') as $currency) {
            if (! is_string($currency)) {
                continue;
            }

            $scoped = (clone $base)->where('currency', $currency);
            $transactions += (clone $scoped)->count();
            $minor = (int) (clone $scoped)->sum('total_minor');

            if ($currency === 'USD') {
                $dollars += (int) round($minor / 100);

                continue;
            }

            // Value foreign sales in USD for the threshold; if no rate is available we
            // cannot honestly value them, so their dollars are omitted (the transactions
            // above still count). Never fabricate a figure.
            $conversion = $this->fx->tryConvert(Money::ofMinor($minor, $currency), 'USD', $asOf);

            if ($conversion !== null) {
                $dollars += (int) round($conversion->converted->minor() / 100);

                continue;
            }

            // No FX rate: the dollar figure for this state is now a FLOOR, not the full total.
            // Surface it so the reported salesDollars is not silently taken as complete — a
            // sales-only-threshold state could otherwise read "below" while actually over.
            Log::warning('nexus.sales_ledger.fx_rate_unavailable', [
                'state' => $state->value,
                'currency' => $currency,
                'minor' => $minor,
                'as_of' => $asOf->toDateString(),
            ]);
        }

        return [$dollars, $transactions];
    }

    /**
     * Operator-declared sales through other channels into the state, for the calendar
     * years the window spans.
     *
     * @return array{0: int, 1: int} [dollars, transactions]
     */
    private function externalActivity(SubdivisionCode $state, Carbon $from, Carbon $until, Carbon $asOf): array
    {
        // Scoped to the SAME single calendar year as the platform side, so the two halves of a
        // window agree. Previously this always pulled both years while the platform side used one
        // continuous span — two different windows summed into one figure.
        $entries = SellerExternalSales::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->where('subdivision', $state->value)
            ->where('period_year', $from->year)
            ->get(['sales_dollars', 'transactions']);

        $dollars = 0;
        $transactions = 0;

        foreach ($entries as $entry) {
            $dollars += $entry->sales_dollars;
            $transactions += $entry->transactions;
        }

        return [$dollars, $transactions];
    }
}
