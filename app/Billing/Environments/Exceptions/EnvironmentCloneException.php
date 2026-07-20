<?php

declare(strict_types=1);

namespace App\Billing\Environments\Exceptions;

use App\Models\Environment;
use RuntimeException;

/**
 * Raised when an environment clone is refused. The clone is deny-by-default: it never
 * overwrites an existing plane (a re-clone into a live key is refused, not a silent replace),
 * never targets the reserved production key, and requires a syntactically valid new key.
 */
class EnvironmentCloneException extends RuntimeException
{
    public static function keyTaken(string $key): self
    {
        return new self("An environment keyed “{$key}” already exists — refusing to overwrite it. Destroy it first or clone into a new key.");
    }

    public static function reservedKey(string $key): self
    {
        return new self("“{$key}” is reserved for the production plane and cannot be a clone target.");
    }

    public static function invalidKey(string $key): self
    {
        return new self("“{$key}” is not a valid environment key — use lower-case letters, digits and dashes (2–40 chars).");
    }

    public static function cannotCloneInto(Environment $source, string $newKey): self
    {
        return new self("Cannot clone environment “{$source->key}” into “{$newKey}”.");
    }
}
