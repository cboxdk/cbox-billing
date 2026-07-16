<?php

declare(strict_types=1);

namespace App\Billing\Hosted\Contracts;

use App\Billing\Hosted\Enums\SessionType;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;

/**
 * Opens and resolves the hosted sessions behind ADR-0009 Path A. The concrete service is
 * the one place a durable {@see BillingSession} row, its opaque non-guessable token, and
 * its TTL are minted and validated; controllers depend on this contract, never on the
 * concrete.
 */
interface ManagesBillingSessions
{
    /**
     * Open a one-shot checkout session collecting payment for `$plan`, priced in
     * `$currency` (or the account's chosen/default currency), returning to `$returnUrl`
     * once the settled webhook activates it.
     */
    public function openCheckout(Organization $organization, Plan $plan, ?string $currency, string $returnUrl): BillingSession;

    /**
     * Open a checkout for `$plan`, reusing an existing still-usable (pending, un-expired)
     * checkout session the org already holds for the SAME plan instead of minting a new
     * one. This keeps a pre-built upgrade deep-link stable and idempotent across repeated
     * denials rather than spawning a fresh row per enforcement call.
     */
    public function openOrReuseCheckout(Organization $organization, Plan $plan, ?string $currency, string $returnUrl): BillingSession;

    /**
     * Open a customer-portal session for `$organization` where it manages its
     * subscription, payment method and invoices, returning to `$returnUrl` when done.
     */
    public function openPortal(Organization $organization, string $returnUrl): BillingSession;

    /**
     * Resolve a session by its opaque token and expected type, or null when no such
     * session exists. A pending session found past its TTL is stamped expired before it
     * is returned, so the caller sees the honest, current status.
     */
    public function locate(string $token, SessionType $type): ?BillingSession;

    /**
     * Flip a pending checkout session to complete, stamping the moment. Idempotent: a
     * re-delivered settlement (or a second call) leaves an already-complete session
     * untouched.
     */
    public function complete(BillingSession $session): void;
}
