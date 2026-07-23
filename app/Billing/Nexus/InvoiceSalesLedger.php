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
    ) {}

    public function activityFor(SubdivisionCode $state): ?SellerActivity
    {
        if ($state->country->value !== 'US') {
            return null;
        }

        $now = Carbon::now();
        $windowStart = $now->copy()->startOfYear()->subYear();

        [$platformDollars, $platformTransactions] = $this->platformActivity($state, $windowStart, $now);
        [$externalDollars, $externalTransactions] = $this->externalActivity($state, $now);

        $salesDollars = $platformDollars + $externalDollars;
        $transactions = $platformTransactions + $externalTransactions;

        if ($salesDollars === 0 && $transactions === 0) {
            return null;
        }

        return new SellerActivity(
            salesDollars: $salesDollars,
            transactions: $transactions,
            periodStart: $windowStart->toDateTimeImmutable(),
            periodEnd: $now->toDateTimeImmutable(),
        );
    }

    /**
     * This platform's invoiced sales into the state over the window, across every
     * currency (non-USD converted to USD as of the reporting date).
     *
     * @return array{0: int, 1: int} [dollars, transactions]
     */
    private function platformActivity(SubdivisionCode $state, Carbon $windowStart, Carbon $asOf): array
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
                $dollars += intdiv($minor, 100);

                continue;
            }

            // Value foreign sales in USD for the threshold; if no rate is available we
            // cannot honestly value them, so their dollars are omitted (the transactions
            // above still count). Never fabricate a figure.
            $conversion = $this->fx->tryConvert(Money::ofMinor($minor, $currency), 'USD', $asOf);

            if ($conversion !== null) {
                $dollars += intdiv($conversion->converted->minor(), 100);

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
    private function externalActivity(SubdivisionCode $state, Carbon $now): array
    {
        $entries = SellerExternalSales::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->where('subdivision', $state->value)
            ->whereIn('period_year', [$now->year, $now->year - 1])
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
