<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Seller\SellerCatalog;
use App\Models\SellerPhysicalPresence;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\PhysicalNexus;
use Illuminate\Support\Carbon;

/**
 * Physical presence from the operator-declared {@see SellerPhysicalPresence} register:
 * the default seller has presence in a state if a declaration for it is in effect
 * today (respecting its optional effective window). Environment-scoped via the model.
 */
readonly class DatabasePhysicalNexus implements PhysicalNexus
{
    public function __construct(private SellerCatalog $sellers) {}

    public function hasPresenceIn(SubdivisionCode $state): bool
    {
        return SellerPhysicalPresence::query()
            ->where('seller_entity_id', $this->sellers->default()->id)
            ->where('subdivision', $state->value)
            ->activeOn(Carbon::now())
            ->exists();
    }
}
