<?php

declare(strict_types=1);

namespace App\Billing\Tax\Contracts;

use App\Billing\Tax\ValueObjects\TaxIdVerification;
use App\Models\Organization;

/**
 * Verifies a customer's tax ID against the authoritative register for its country (VIES for the
 * EU, HMRC for the UK) and records the verdict — including the evidence for it — on the org.
 *
 * A contract rather than a concrete call because the outcome decides whether a cross-border supply
 * is reverse-charged, and that has to be substitutable: a host may front VIES with its own cache
 * or a commercial aggregator, and tests must be able to drive every branch (valid, invalid,
 * inconclusive, unsupported country) without reaching the network.
 */
interface VerifiesCustomerTaxIds
{
    /**
     * Verify the organization's currently-stored tax ID and persist the outcome.
     *
     * Never throws for a service outage: an unreachable register yields an INCONCLUSIVE result,
     * which leaves the org unvalidated. That is the safe direction — an unvalidated buyer is
     * charged VAT, which is recoverable, whereas wrongly zero-rating a supply leaves the seller
     * owing tax it never collected.
     */
    public function verify(Organization $organization): TaxIdVerification;
}
