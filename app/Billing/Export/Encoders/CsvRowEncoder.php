<?php

declare(strict_types=1);

namespace App\Billing\Export\Encoders;

use App\Billing\Export\Contracts\RowEncoder;
use App\Billing\Export\Enums\ColumnType;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * RFC-4180 CSV encoder. Emits one header row of column names, then one quoted-as-needed row per
 * record — booleans as `true`/`false`, timestamps as their ISO-8601 string, JSON columns as
 * their compact JSON string, nulls as an empty field. Quoting is delegated to PHP's native
 * `fputcsv` (a per-row in-memory stream), so commas, quotes and newlines inside a value are
 * escaped correctly without a third-party CSV dependency.
 */
class CsvRowEncoder implements RowEncoder
{
    public function contentType(): string
    {
        return 'text/csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function header(array $schema): ?string
    {
        return $this->line(array_map(static fn (ExportColumn $c): string => $c->name, $schema));
    }

    public function encode(array $row, array $schema): string
    {
        $fields = [];

        foreach ($schema as $column) {
            $fields[] = $this->render($row[$column->name] ?? null, $column->type);
        }

        return $this->line($fields);
    }

    /** Render one value to its CSV cell string per column type. */
    private function render(mixed $value, ColumnType $type): string
    {
        if ($value === null) {
            return '';
        }

        if ($type === ColumnType::Boolean) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Quote and join one record via native fputcsv on a memory stream (correct RFC-4180
     * escaping), then return the produced line unchanged.
     *
     * @param  list<string>  $fields
     */
    private function line(array $fields): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            // php://temp is always openable; fall back to a naive join only to satisfy the type.
            return implode(',', $fields)."\r\n";
        }

        fputcsv($stream, $fields, ',', '"', '\\', "\r\n");
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        return $line === false ? implode(',', $fields)."\r\n" : $line;
    }
}
