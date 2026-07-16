<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\CreatesGatewayCustomer;
use App\Models\Organization;

/**
 * The gateway-customer factory for gateways with no SDK customers API — the manual /
 * off-line gateway (and any other vault-less gateway). There is no remote customer to
 * create, so it returns a documented, deterministic local handle `"{gateway}:{org}"`
 * (e.g. `manual:org_42`). It is still stored once by the resolver and reused, so the
 * account carried on every intent for the org is stable and self-describing.
 */
readonly class ManualGatewayCustomerFactory implements CreatesGatewayCustomer
{
    public function create(Organization $organization, string $gateway): string
    {
        return $gateway.':'.$organization->id;
    }
}
