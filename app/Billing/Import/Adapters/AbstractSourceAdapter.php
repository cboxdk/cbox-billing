<?php

declare(strict_types=1);

namespace App\Billing\Import\Adapters;

use App\Billing\Import\Contracts\SourceAdapter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Shared field-coercion + unit/date-conversion helpers every concrete adapter builds on. The
 * per-provider adapters differ only in which field names they read and which unit convention
 * they apply; the plumbing to read a record tolerantly (first present of several aliases, safe
 * scalar coercion) and to convert units/dates lives here once.
 */
abstract readonly class AbstractSourceAdapter implements SourceAdapter
{
    /**
     * The first present, non-empty string value among `$keys`, or null.
     *
     * @param  array<string, mixed>  $record
     */
    protected function string(array $record, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->dig($record, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * The first present integer value among `$keys` (numeric strings coerced), or null.
     *
     * @param  array<string, mixed>  $record
     */
    protected function int(array $record, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->dig($record, $key);

            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return (int) round($value);
            }

            if (is_string($value) && is_numeric(trim($value))) {
                return (int) round((float) trim($value));
            }
        }

        return null;
    }

    /**
     * A minor-unit amount from a provider that already stores minor units (Stripe, Chargebee):
     * pass the integer through. Null when no key is present.
     *
     * @param  array<string, mixed>  $record
     */
    protected function minorFromMinor(array $record, string ...$keys): ?int
    {
        return $this->int($record, ...$keys);
    }

    /**
     * A minor-unit amount from a provider that stores DECIMAL MAJOR units (Recurly's
     * `unit_amount` = "49.00"): multiply up by 100. NOTE (assumption): this assumes a
     * two-decimal currency — a zero-decimal currency (JPY, KRW) would need per-currency exponent
     * handling; the fixtures + supported currencies are two-decimal, and the docs flag this.
     *
     * @param  array<string, mixed>  $record
     */
    protected function minorFromMajor(array $record, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->dig($record, $key);

            if (is_int($value) || is_float($value)) {
                return (int) round(((float) $value) * 100);
            }

            if (is_string($value) && is_numeric(trim($value))) {
                return (int) round(((float) trim($value)) * 100);
            }
        }

        return null;
    }

    /**
     * Parse a provider timestamp — a unix epoch (int/numeric string) or an ISO-8601 / RFC date
     * string — into a UTC {@see CarbonImmutable}, or null when absent/unparseable.
     *
     * @param  array<string, mixed>  $record
     */
    protected function timestamp(array $record, string ...$keys): ?CarbonImmutable
    {
        foreach ($keys as $key) {
            $value = $this->dig($record, $key);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            try {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    return CarbonImmutable::createFromTimestampUTC((int) $value);
                }

                if (is_string($value)) {
                    return CarbonImmutable::parse($value)->utc();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /** An uppercased ISO currency, or null. */
    protected function currency(?string $value): ?string
    {
        return $value === null ? null : strtoupper($value);
    }

    /**
     * Normalise an arbitrary decoded value into a string-keyed record (a nested JSON object),
     * so the coercion helpers can read it. A non-array yields an empty record.
     *
     * @return array<string, mixed>
     */
    protected function asRecord(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $record = [];

        foreach ($value as $key => $item) {
            $record[(string) $key] = $item;
        }

        return $record;
    }

    /**
     * Read a possibly dotted key (`recurring.interval`) out of a nested record.
     *
     * @param  array<string, mixed>  $record
     */
    protected function dig(array $record, string $key): mixed
    {
        if (array_key_exists($key, $record)) {
            return $record[$key];
        }

        if (! str_contains($key, '.')) {
            return null;
        }

        $cursor = $record;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
