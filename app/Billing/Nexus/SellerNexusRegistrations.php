<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Seller\SellerCatalog;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusRegistrations;

/**
 * Reports the states the current default selling entity already holds a tax
 * registration in, from its assembled {@see SellerCatalog}
 * identity (DB `seller_tax_registrations` rows, falling back to the `billing.seller`
 * config) — the same registrations that drive the tax outcome. A handled
 * obligation then reports as `Registered` rather than an outstanding action.
 */
readonly class SellerNexusRegistrations implements NexusRegistrations
{
    public function __construct(private SellerCatalog $sellers) {}

    public function isRegisteredIn(SubdivisionCode $state): bool
    {
        return $this->sellers->default()->toSellerRegistrations()->isRegisteredInSubdivision($state);
    }
}
