<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;

/**
 * Resolves the payment-gateway customer id an intent must be created against (ADR-0009
 * Path B). The bound {@see PaymentGateway} vaults cards
 * and off-session methods by the gateway's own customer handle (`cus_…`), never by the
 * raw org id — so the `account` on a PaymentIntent / SetupIntent request is this id.
 *
 * On the org's FIRST intent for the active gateway the customer is created (via the
 * gateway/adapter) and stored; every later intent reuses the stored id. Deny-by-default
 * lives upstream in the controllers (per-org 403); this seam only maps an org to its
 * gateway customer.
 */
interface ResolvesGatewayCustomer
{
    /**
     * The gateway customer id for `$organization` under the currently-bound gateway,
     * creating and storing it once on first use and reusing it thereafter.
     */
    public function resolve(Organization $organization): string;
}
