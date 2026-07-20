<?php

declare(strict_types=1);

namespace App\Billing\Features\Enums;

use App\Models\Feature;

/**
 * How a config {@see Feature}'s value is typed. The value is persisted as a string
 * (on the plan grant or the org override) and coerced through this enum on resolution, so the
 * resolved feature set carries a real `int`/`string`, never a loose stringly-typed value. A
 * boolean feature has no value type (null).
 */
enum ConfigValueType: string
{
    case Integer = 'integer';
    case String = 'string';

    /**
     * Coerce a stored string value into its typed form. A null/blank stored value stays null.
     * An integer type parses digits (a non-numeric stored value is refused as null rather than
     * silently coerced to 0), so a malformed row never fabricates a limit.
     */
    public function cast(?string $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this) {
            self::Integer => is_numeric($value) ? (int) $value : null,
            self::String => $value,
        };
    }

    /** A short human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::Integer => 'Integer',
            self::String => 'String',
        };
    }
}
