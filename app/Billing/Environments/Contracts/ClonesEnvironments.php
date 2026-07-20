<?php

declare(strict_types=1);

namespace App\Billing\Environments\Contracts;

use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Models\Environment;

/**
 * Clones a billing {@see Environment}: creates a new sandbox plane and DEEP-COPIES the source
 * environment's CONFIG (catalog, branding, templates, storefront, experiments, dunning, coupons)
 * into it, preserving intra-config relationships. Transactional/tenant data (subscriptions,
 * invoices, customers, ledger) is NEVER copied — the clone starts with an empty book. Bound
 * contracts-first so the command, console action and tests all resolve the one implementation.
 */
interface ClonesEnvironments
{
    /**
     * Clone `$source` into a brand-new sandbox environment keyed `$newKey`.
     *
     * @param  string|null  $name  human label for the new environment (defaults to the key)
     *
     * @throws EnvironmentCloneException when `$newKey` is reserved, invalid, or already taken
     */
    public function clone(Environment $source, string $newKey, ?string $name = null): Environment;

    /**
     * Deep-copy `$source`'s config surface into the EXISTING `$target` environment, preserving
     * intra-config relationships. The reseed seam a reset re-clones config through — `$target`
     * must already have had its own config wiped (this only INSERTs), and transactional data is
     * never copied.
     */
    public function copyConfigInto(Environment $source, Environment $target): void;
}
