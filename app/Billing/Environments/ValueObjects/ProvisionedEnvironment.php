<?php

declare(strict_types=1);

namespace App\Billing\Environments\ValueObjects;

use App\Models\Environment;

/**
 * The result of provisioning a new sandbox: the created {@see Environment} and — when a token was
 * requested — the one-time plaintext of an API token bound to that plane. The plaintext is
 * returned exactly once (only its SHA-256 is stored) so CI can capture it immediately; it is never
 * available again.
 */
readonly class ProvisionedEnvironment
{
    public function __construct(
        public Environment $environment,
        public ?string $tokenPlaintext = null,
        public bool $cloned = false,
    ) {}
}
