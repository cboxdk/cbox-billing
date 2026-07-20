<?php

declare(strict_types=1);

namespace App\Billing\Environments\Contracts;

use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\ValueObjects\EnvironmentTeardownResult;
use App\Models\Environment;

/**
 * Destroys a sandbox environment: a hard teardown for CI that deletes the environment row AND all
 * of its plane data — config + transactional — plus its gateway credentials and any API tokens
 * bound to it, transactionally, so nothing of the plane survives. Production is never destroyed.
 */
interface DestroysEnvironments
{
    /**
     * Destroy `$environment` and everything scoped to it.
     *
     * @throws EnvironmentProtectedException when `$environment` is the protected production plane
     */
    public function destroy(Environment $environment): EnvironmentTeardownResult;
}
