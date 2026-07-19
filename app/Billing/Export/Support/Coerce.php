<?php

declare(strict_types=1);

namespace App\Billing\Export\Support;

use Carbon\CarbonImmutable;

/**
 * Narrowing helpers that turn a raw database value (`mixed`, as the query builder hands back
 * driver-dependent scalars) into the exact typed scalar an export column promises. Row
 * projection is a serialization boundary, so this is where `mixed` is honestly resolved once —
 * with real type checks, not casts that paper over an unknown shape.
 */
class Coerce
{
    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return is_numeric($value) ? ((int) $value) === 1 : null;
    }

    /** A timestamp column rendered as ISO-8601 UTC (the stable, driver-independent wire form). */
    public static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** A millisecond-epoch integer rendered as ISO-8601 UTC. */
    public static function fromMillis(mixed $value): ?string
    {
        $ms = self::int($value);

        if ($ms === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs($ms)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * A JSON column left as its compact JSON string (the CSV form; the NDJSON encoder re-embeds
     * it as a nested object). A null or already-decoded value is normalised back to a string.
     */
    public static function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
