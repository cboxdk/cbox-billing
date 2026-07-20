<?php

declare(strict_types=1);

namespace App\Billing\Environments\Contracts;

use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Environments\ValueObjects\ProvisionedEnvironment;
use App\Models\Environment;

/**
 * Provisions a new SANDBOX environment for programmatic / CI use: a fresh plane
 * (`type = sandbox`, `gateway_key_mode = test`), optionally deep-copying a source environment's
 * config via the {@see ClonesEnvironments} cloner, and optionally minting an API token bound to
 * the new plane so CI immediately has a scoped credential. Contracts-first so the API controller,
 * console and tests all resolve the one implementation.
 */
interface CreatesEnvironments
{
    /**
     * Create a sandbox environment keyed `$key`.
     *
     * @param  string|null  $name  human label (defaults to the key)
     * @param  Environment|null  $cloneFrom  when given, deep-copy this environment's config into the new plane
     * @param  bool  $withToken  when true, mint an operator API token bound to the new plane and return its plaintext once
     *
     * @throws EnvironmentCloneException when `$key` is reserved, invalid, or already taken
     */
    public function create(string $key, ?string $name = null, ?Environment $cloneFrom = null, bool $withToken = false): ProvisionedEnvironment;
}
