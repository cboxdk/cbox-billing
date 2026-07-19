<?php

declare(strict_types=1);

namespace App\Billing\Export\Encoders;

use App\Billing\Export\Contracts\RowEncoder;
use App\Billing\Export\Enums\ColumnType;

/**
 * Newline-delimited JSON encoder — one JSON object per line, the format Snowflake/BigQuery/
 * Redshift ingest natively. Types are preserved: integers stay JSON numbers, booleans stay
 * booleans, timestamps stay their ISO-8601 string, and a JSON column is re-embedded as a
 * NESTED object/array (decoded from its stored string) rather than a doubly-encoded string, so
 * a warehouse VARIANT/JSON/SUPER column receives real structure. Headerless by contract.
 */
class NdjsonRowEncoder implements RowEncoder
{
    public function contentType(): string
    {
        return 'application/x-ndjson';
    }

    public function extension(): string
    {
        return 'ndjson';
    }

    public function header(array $schema): ?string
    {
        return null;
    }

    public function encode(array $row, array $schema): string
    {
        $object = [];

        foreach ($schema as $column) {
            $object[$column->name] = $this->render($row[$column->name] ?? null, $column->type);
        }

        // Preserve unicode and forward slashes verbatim; a failed encode degrades to an empty
        // object line rather than aborting the whole stream.
        $json = json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($json === false ? '{}' : $json)."\n";
    }

    /** Resolve one value to its JSON-typed representation per column type. */
    private function render(mixed $value, ColumnType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ColumnType::Integer => is_numeric($value) ? (int) $value : null,
            ColumnType::Boolean => (bool) $value,
            ColumnType::Json => $this->decode($value),
            // String and Timestamp are already their exact wire string.
            ColumnType::String, ColumnType::Timestamp => is_scalar($value) ? (string) $value : null,
        };
    }

    /** Decode a stored JSON string back to structure; leave a non-decodable value as-is. */
    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return $decoded === null && strtolower(trim($value)) !== 'null' ? $value : $decoded;
    }
}
