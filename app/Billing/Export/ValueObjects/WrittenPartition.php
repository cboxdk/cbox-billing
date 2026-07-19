<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\SyncMode;

/**
 * The record of one staged file the object-store sink wrote: which dataset and plane it holds,
 * the disk-relative path, the format and load mode, the row/byte counts, the partition date,
 * and the cursor window it covers. It is what a load-manifest generator reads to phrase the
 * exact `COPY`/`bq load`/DDL that ingests THIS file, and what the run log persists.
 */
readonly class WrittenPartition
{
    public function __construct(
        public string $dataset,
        public bool $livemode,
        public string $disk,
        public string $path,
        public string $manifestPath,
        public ExportFormat $format,
        public SyncMode $syncMode,
        public int $rows,
        public int $bytes,
        public string $partitionDate,
        public ?string $cursorFrom,
        public ?string $cursorTo,
    ) {}

    /** The directory the staged file lives in — the glob a warehouse load points at. */
    public function directory(): string
    {
        $slash = strrpos($this->path, '/');

        return $slash === false ? '' : substr($this->path, 0, $slash);
    }

    /** The bare filename of the staged file. */
    public function filename(): string
    {
        $slash = strrpos($this->path, '/');

        return $slash === false ? $this->path : substr($this->path, $slash + 1);
    }
}
