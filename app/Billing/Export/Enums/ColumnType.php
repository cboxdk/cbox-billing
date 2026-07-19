<?php

declare(strict_types=1);

namespace App\Billing\Export\Enums;

/**
 * The logical type of an export column — the stable contract a downstream warehouse maps
 * to a physical column type, and the hint the encoders use to render a value correctly
 * (an integer stays a JSON number in NDJSON and an unquoted field in CSV; a timestamp is
 * always emitted ISO-8601 UTC; a JSON column is embedded as a nested object in NDJSON and
 * a JSON string in CSV).
 *
 * Money is always exported as an integer count of MINOR units (e.g. øre/cents) in a
 * companion `*_minor` column, never a lossy float — the amount is paired with its ISO-4217
 * currency column so a consumer scales it deterministically.
 */
enum ColumnType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Timestamp = 'timestamp';
    case Json = 'json';

    /** The Snowflake physical type this maps to. */
    public function snowflake(): string
    {
        return match ($this) {
            self::String => 'VARCHAR',
            self::Integer => 'NUMBER',
            self::Boolean => 'BOOLEAN',
            self::Timestamp => 'TIMESTAMP_NTZ',
            self::Json => 'VARIANT',
        };
    }

    /** The BigQuery physical type this maps to. */
    public function bigQuery(): string
    {
        return match ($this) {
            self::String => 'STRING',
            self::Integer => 'INT64',
            self::Boolean => 'BOOL',
            self::Timestamp => 'TIMESTAMP',
            self::Json => 'JSON',
        };
    }

    /** The Redshift physical type this maps to. */
    public function redshift(): string
    {
        return match ($this) {
            self::String => 'VARCHAR(65535)',
            self::Integer => 'BIGINT',
            self::Boolean => 'BOOLEAN',
            self::Timestamp => 'TIMESTAMP',
            // SUPER holds semi-structured JSON in Redshift.
            self::Json => 'SUPER',
        };
    }
}
