<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Seller\SellerCatalog;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\SalesLedger;
use Cbox\Nexus\ValueObjects\SellerActivity;
use Illuminate\Support\Carbon;

/**
 * The {@see SalesLedger} for the nexus engine, aggregated from this app's invoices:
 * the default selling entity's finalized US sales into a state, over the common
 * "previous or current calendar year" window.
 *
 * It counts only USD invoices in a collectible state (open/paid) whose buyer
 * organization's place of supply ({@see Organization::$billing_subdivision})
 * is the state, using the gross `total_minor`. Queries run through the Eloquent
 * models, so the environment (plane) scope is applied automatically; sales are
 * further isolated to the current default seller.
 *
 * Measurement precision caveat: economic-nexus measurement periods vary by state
 * (previous / current / rolling twelve months); the SalesLedger contract carries no
 * period, so this uses the dominant previous-or-current-calendar-year window. Refine
 * per-state if a jurisdiction's rule demands it.
 */
readonly class InvoiceSalesLedger implements SalesLedger
{
    public function __construct(
        private SellerCatalog $sellers,
        private string $currency = 'USD',
    ) {}

    public function activityFor(SubdivisionCode $state): ?SellerActivity
    {
        if ($state->country->value !== 'US') {
            return null;
        }

        $windowStart = Carbon::now()->startOfYear()->subYear();

        // Buyer place of supply lives on the organization (env-scoped). Resolve the
        // matching orgs first, then aggregate their invoices — keeps the columns on
        // their own typed model and the environment scope applied throughout.
        $organizationIds = Organization::query()
            ->where('billing_country', 'US')
            ->where('billing_subdivision', $state->value)
            ->pluck('id')
            ->all();

        if ($organizationIds === []) {
            return null;
        }

        $base = Invoice::query()
            ->where('seller', $this->sellers->default()->id)
            ->where('currency', $this->currency)
            ->whereIn('status', [InvoiceStatus::Open, InvoiceStatus::Paid])
            ->where('issued_at', '>=', $windowStart)
            ->whereIn('organization_id', $organizationIds);

        $transactions = (clone $base)->count();

        if ($transactions === 0) {
            return null;
        }

        $minor = (int) (clone $base)->sum('total_minor');

        return new SellerActivity(
            salesDollars: intdiv($minor, 100),
            transactions: $transactions,
            periodStart: $windowStart->toDateTimeImmutable(),
            periodEnd: Carbon::now()->toDateTimeImmutable(),
        );
    }
}
