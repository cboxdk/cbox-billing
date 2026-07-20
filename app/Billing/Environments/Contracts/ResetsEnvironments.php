<?php

declare(strict_types=1);

namespace App\Billing\Environments\Contracts;

use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\ValueObjects\EnvironmentTeardownResult;
use App\Models\Environment;

/**
 * Resets a sandbox environment: wipes its transactional/tenant data (the runtime book — subscriptions,
 * invoices, customers, ledger/wallet, dunning, redemptions, licenses, webhook deliveries, seats, …)
 * while keeping its config (catalog, branding, storefront, gateway credentials). Optionally
 * re-seeds the config by re-cloning it from another environment first. Production is never reset.
 */
interface ResetsEnvironments
{
    /**
     * Reset `$environment` — wipe its transactional data, keep (or re-clone) its config.
     *
     * @param  Environment|null  $reseedFrom  when given, ALSO wipe this plane's config and re-copy it from here
     *
     * @throws EnvironmentProtectedException when `$environment` is the protected production plane
     */
    public function reset(Environment $environment, ?Environment $reseedFrom = null): EnvironmentTeardownResult;
}
