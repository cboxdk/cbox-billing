<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\PhysicalNexus;

/**
 * Physical presence (offices, employees, inventory) is operator-asserted — there is
 * no data model for it — so it is declared as a config list of ISO 3166-2 states
 * (`nexus.physical_presence`, e.g. `['US-CA', 'US-TX']`). Empty by default.
 */
readonly class ConfigPhysicalNexus implements PhysicalNexus
{
    /**
     * @param  list<string>  $states
     */
    public function __construct(private array $states = []) {}

    public function hasPresenceIn(SubdivisionCode $state): bool
    {
        return in_array($state->value, $this->states, true);
    }
}
