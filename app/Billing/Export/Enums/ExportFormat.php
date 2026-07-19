<?php

declare(strict_types=1);

namespace App\Billing\Export\Enums;

/**
 * The two on-the-wire encodings every dataset can be streamed as. CSV is the
 * spreadsheet/RevOps interchange (one header row, RFC-4180 quoting); NDJSON
 * (newline-delimited JSON) is the warehouse/lake interchange — one JSON object per
 * line, types preserved, the format Snowflake/BigQuery/Redshift load natively.
 *
 * The raw usage-event stream is only ever meaningful as NDJSON (it carries nested and
 * typed fields), but both encodings are offered for every dataset for symmetry.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Ndjson = 'ndjson';

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            // application/x-ndjson is the registered media type for newline-delimited JSON.
            self::Ndjson => 'application/x-ndjson',
        };
    }

    /** Parse a request/credential string, deny-by-default to NDJSON for anything unrecognised. */
    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Ndjson;
    }
}
