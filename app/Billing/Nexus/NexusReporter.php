<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Seller\SellerCatalog;
use App\Models\Organization;
use App\Models\SellerTaxRegistration;
use Cbox\Geo\Exceptions\InvalidSubdivisionCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusEngine;
use Cbox\Nexus\ValueObjects\NexusReport;

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
    ) {}

    public function report(): NexusReport
    {
        return $this->engine->report($this->relevantStates());
    }

    /**
     * The distinct US states worth evaluating for the default seller: buyer places
     * of supply plus held registrations. Both queries are environment-scoped.
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

        $states = [];

        foreach ($fromBuyers->merge($fromRegistrations)->unique() as $value) {
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
