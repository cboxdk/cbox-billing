<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Seller\SellerCatalog;
use App\Models\Organization;
use App\Models\SellerExternalSales;
use App\Models\SellerPhysicalPresence;
use App\Models\SellerTaxRegistration;
use Cbox\Geo\Exceptions\InvalidSubdivisionCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusEngine;
use Cbox\Nexus\ValueObjects\NexusReport;
use Illuminate\Support\Carbon;

/**
 * Runs the nexus engine across the US states the default selling entity is exposed
 * to — the states it has US buyers in (any org whose place of supply is a US state)
 * plus the states it already holds a registration in — and returns the platform
 * {@see NexusReport}. This is the app's consumption of the engine: the seams feed it
 * the seller's sales/registrations, this turns that into a per-state standing the
 * console and alerts surface.
 */
readonly class NexusReporter
{
    public function __construct(
        private NexusEngine $engine,
        private SellerCatalog $sellers,
        private bool $soleSalesChannel = false,
    ) {}

    public function report(): NexusReport
    {
        return $this->engine->report($this->relevantStates());
    }

    /**
     * Whether this platform is the seller's ONLY US sales channel. When false, the
     * report's sales reflect only invoices issued here — other channels also count
     * toward each state's threshold — so a UI must show that a Below/Approaching
     * state may already be Triggered once all channels are combined.
     */
    public function soleSalesChannel(): bool
    {
        return $this->soleSalesChannel;
    }

    /**
     * The distinct US states worth evaluating for the default seller: buyer places
     * of supply, held registrations (any reason), and declared physical presence —
     * so a state with a nexus trigger but no sales still surfaces. The Eloquent
     * queries are environment-scoped.
     *
     * @return list<SubdivisionCode>
     */
    private function relevantStates(): array
    {
        $fromBuyers = Organization::query()
            ->where('billing_country', 'US')
            ->whereNotNull('billing_subdivision')
            ->distinct()
            ->pluck('billing_subdivision');

        $fromRegistrations = SellerTaxRegistration::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->where('country', 'US')
            ->whereNotNull('subdivision')
            ->pluck('subdivision');

        $now = Carbon::now();

        $fromPresence = SellerPhysicalPresence::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->activeOn($now)
            ->pluck('subdivision');

        // A state may have sales ONLY through other channels (no platform buyer, no
        // registration, no presence) yet still be over its threshold — surface it.
        $fromExternalSales = SellerExternalSales::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->whereIn('period_year', [$now->year, $now->year - 1])
            ->distinct()
            ->pluck('subdivision');

        $values = $fromBuyers
            ->merge($fromRegistrations)
            ->merge($fromPresence)
            ->merge($fromExternalSales)
            ->unique();

        $states = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            try {
                $states[] = new SubdivisionCode($value);
            } catch (InvalidSubdivisionCode) {
                continue;
            }
        }

        return $states;
    }
}
