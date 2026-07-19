<?php

declare(strict_types=1);

namespace App\Billing\Export\Contracts;

use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\WarehouseSink as SinkConfig;

/**
 * A destination a dataset partition is delivered to. The real, working implementation stages
 * the rows as partitioned files in object storage (the way Snowflake/BigQuery/Redshift actually
 * ingest at scale) and writes a sidecar manifest; the load side is then a `COPY`/`bq load`/DDL
 * an operator or scheduled loader runs against the staged files.
 */
interface WarehouseSink
{
    /**
     * Stage the dataset's scoped rows for the given sink configuration and return the record of
     * the file written (path, counts, cursor window). Streams internally — never buffers the
     * whole dataset.
     */
    public function deliver(SinkConfig $sink, ExportDataset $dataset, ExportFormat $format, ExportQuery $query): WrittenPartition;
}
