<?php

declare(strict_types=1);

namespace App\Billing\Payments\ValueObjects;

use App\Billing\Payments\WebhookPlaneResolver;

/**
 * The plane-addressing signals peeked out of a raw (still UNVERIFIED) settlement webhook body, in
 * descending order of how well each one identifies a single billing PLANE. See
 * {@see WebhookPlaneResolver} for how they are consumed.
 *
 *  - `$gatewayObject` — the gateway's own object id for this settlement (`pi_…` / `ch_…` for
 *    Stripe). GLOBALLY unique: a gateway never mints the same object id twice, and the test and
 *    live key spaces are disjoint. The strongest signal we have.
 *  - `$gatewayCustomer` — the gateway's vaulted customer handle (`cus_…`). Also globally unique per
 *    gateway, and `gateway_customers` is NOT copied when an environment is cloned, so the mapping
 *    row that matches identifies the owning plane outright.
 *  - `$reference` — the settlement reference the gateway echoes back (the INVOICE NUMBER for
 *    settlements/renewals, the checkout session's payment reference for hosted activation). This is
 *    the WEAKEST signal: invoice numbers are only unique per `(seller, number)`, and cloning an
 *    environment duplicates the seller entities together with their invoice prefix — so
 *    `CBOX-DK-2026-00001` can legitimately exist in production AND in a sandbox at once.
 *  - `$gateway` — the gateway segment of the webhook URL, used to scope the customer lookup for the
 *    manual/host shape (a Stripe-shaped body always resolves against the `stripe` gateway).
 */
readonly class SettlementSignals
{
    public function __construct(
        public string $reference = '',
        public string $gatewayObject = '',
        public string $gatewayCustomer = '',
        public string $gateway = '',
    ) {}
}
