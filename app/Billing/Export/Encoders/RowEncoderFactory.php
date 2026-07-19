<?php

declare(strict_types=1);

namespace App\Billing\Export\Encoders;

use App\Billing\Export\Contracts\RowEncoder;
use App\Billing\Export\Enums\ExportFormat;

/**
 * Resolves the {@see RowEncoder} for an {@see ExportFormat}. A tiny, explicit map keeps the
 * format→encoder binding in one place (and deny-by-default: a format with no encoder is a
 * programming error, not a silent fallthrough).
 */
class RowEncoderFactory
{
    public function __construct(
        private readonly CsvRowEncoder $csv,
        private readonly NdjsonRowEncoder $ndjson,
    ) {}

    public function for(ExportFormat $format): RowEncoder
    {
        return match ($format) {
            ExportFormat::Csv => $this->csv,
            ExportFormat::Ndjson => $this->ndjson,
        };
    }
}
