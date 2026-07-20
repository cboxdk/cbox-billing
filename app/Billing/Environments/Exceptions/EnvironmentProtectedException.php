<?php

declare(strict_types=1);

namespace App\Billing\Environments\Exceptions;

use App\Models\Environment;
use RuntimeException;

/**
 * Raised when a destructive environment operation targets the PROTECTED production plane. Production
 * can never be destroyed or reset — the two hard invariants (one production plane, always live keys)
 * mean its book is the real business, not a disposable dataset. Enforced server-side in the API,
 * the console and the CLI (deny-by-default), so no surface can bypass the guard.
 */
class EnvironmentProtectedException extends RuntimeException
{
    public static function cannotDestroy(Environment $environment): self
    {
        return new self(sprintf('Environment “%s” is protected and cannot be destroyed. Production is never disposable.', $environment->key));
    }

    public static function cannotReset(Environment $environment): self
    {
        return new self(sprintf('Environment “%s” is protected and cannot be reset. Production data is never wiped.', $environment->key));
    }
}
