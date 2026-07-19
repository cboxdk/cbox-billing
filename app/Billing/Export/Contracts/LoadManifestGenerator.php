<?php

declare(strict_types=1);

namespace App\Billing\Export\Contracts;

use App\Billing\Export\Enums\Warehouse;
use App\Billing\Export\ValueObjects\WarehouseTarget;
use App\Billing\Export\ValueObjects\WrittenPartition;

/**
 * Emits the exact, copy-paste load statement a specific warehouse runs to ingest a staged
 * partition — the real `COPY INTO` (Snowflake/Redshift), `bq load` / external-table DDL
 * (BigQuery) an operator or a scheduled loader executes. This is how these warehouses actually
 * load at scale (from staged object-storage files, not row-by-row inserts), so it is a first-
 * class, correct artifact — not a stub, and it bundles no heavyweight vendor SDK.
 */
interface LoadManifestGenerator
{
    public function warehouse(): Warehouse;

    /**
     * The DDL + load statement(s) for `$partition`, phrased for `$target`. The dataset supplies
     * the column schema (so the table DDL and any MERGE key are correct); the sync mode decides
     * whether it is a plain append, a keyed MERGE, or a truncate-and-replace.
     */
    public function generate(ExportDataset $dataset, WrittenPartition $partition, WarehouseTarget $target): string;
}
