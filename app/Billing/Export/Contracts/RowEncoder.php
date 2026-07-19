<?php

declare(strict_types=1);

namespace App\Billing\Export\Contracts;

use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * Encodes a dataset's typed rows into one on-the-wire format (CSV or NDJSON). The encoder is
 * stateless and per-row: it emits an optional header once, then one encoded chunk per row, so
 * the caller can stream straight to an HTTP response or an object-store stream without ever
 * holding more than a single row in memory.
 */
interface RowEncoder
{
    public function contentType(): string;

    public function extension(): string;

    /**
     * The header chunk emitted once before any row (the CSV column row), or null when the
     * format is headerless (NDJSON).
     *
     * @param  list<ExportColumn>  $schema
     */
    public function header(array $schema): ?string;

    /**
     * Encode one row into its serialized chunk (including any trailing newline), rendering each
     * value per its column's type.
     *
     * @param  array<string, scalar|null>  $row
     * @param  list<ExportColumn>  $schema
     */
    public function encode(array $row, array $schema): string;
}
