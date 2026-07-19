<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use App\Billing\Export\DataExporter;

/**
 * The tally of a single streamed pass over a dataset: how many rows and bytes were written,
 * and the lowest/highest cursor values seen (the watermark the next incremental run starts
 * after). Produced by {@see DataExporter::pump()} and consumed by both the
 * HTTP download path and the object-store sink.
 */
readonly class PumpResult
{
    public function __construct(
        public int $rows,
        public int $bytes,
        public ?string $cursorFrom = null,
        public ?string $cursorTo = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === 0;
    }
}
