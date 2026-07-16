<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Models\Organization;

/**
 * Mints a fresh gateway customer for an organization at the active gateway — the
 * creation half of {@see ResolvesGatewayCustomer}, split out so the "create once" policy
 * (look up, else create, else store) stays gateway-agnostic while the actual minting is
 * gateway-specific: an SDK-backed gateway calls its customers API and returns the new
 * `cus_…`; a manual/off-line gateway returns a documented deterministic handle.
 *
 * Only ever called when no mapping exists yet — the resolver guarantees it runs at most
 * once per `(organization, gateway)`.
 */
interface CreatesGatewayCustomer
{
    /** Create a new gateway customer for `$organization` under `$gateway` and return its id. */
    public function create(Organization $organization, string $gateway): string;
}
