<?php

declare(strict_types=1);

namespace App\Billing\Audit\Hashing;

/**
 * Deterministic canonicalization of an audit event's hashable payload. The exact same input
 * must always produce the exact same bytes — across PHP versions, machines and re-runs — or the
 * chain would never re-verify. Keys are sorted recursively and JSON is emitted with slashes and
 * unicode left unescaped, so the canonical form is stable and human-diffable.
 */
class CanonicalPayload
{
    /**
     * The canonical JSON string of a hashable payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $normalized = self::normalize($payload);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
            ?: '{}';
    }

    /**
     * Recursively sort array keys so object member order never affects the canonical bytes.
     * A list (sequential integer keys) keeps its order; a map is key-sorted.
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
