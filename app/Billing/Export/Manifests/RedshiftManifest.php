<?php

declare(strict_types=1);

namespace App\Billing\Export\Manifests;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Enums\Warehouse;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\WarehouseTarget;
use App\Billing\Export\ValueObjects\WrittenPartition;

/**
 * Emits the real Amazon Redshift load for a staged partition: the target-table DDL and a
 * `COPY … FROM 's3://…' IAM_ROLE …` — appending for the event stream, and for an upsert the
 * canonical Redshift pattern (COPY into a staging table, then `DELETE … USING` + `INSERT …
 * SELECT` on the natural key inside a transaction), with `TRUNCATE` + `COPY` for a snapshot.
 * This is exactly how Redshift bulk-loads from S3.
 */
class RedshiftManifest extends AbstractManifest
{
    public function warehouse(): Warehouse
    {
        return Warehouse::Redshift;
    }

    public function generate(ExportDataset $dataset, WrittenPartition $partition, WarehouseTarget $target): string
    {
        $schema = $dataset->schema();
        $table = $target->table($dataset->key());
        $location = rtrim($target->externalBase, '/').'/'.$partition->directory().'/';
        $iamRole = $target->credential ?? '<redshift-iam-role-arn>';

        $ddl = "CREATE TABLE IF NOT EXISTS {$table} (\n".
            $this->columnDdl($schema, static fn (ExportColumn $c): string => $c->type->redshift()).
            "\n);";

        $body = match ($partition->syncMode) {
            SyncMode::Append => $this->copy($table, $location, $iamRole, $partition->format),
            SyncMode::Snapshot => "TRUNCATE TABLE {$table};\n\n".$this->copy($table, $location, $iamRole, $partition->format),
            SyncMode::Upsert => $this->upsert($dataset, $table, $location, $iamRole, $partition->format, $schema),
        };

        return $this->banner('Redshift', $dataset, $partition)."\n\n".
            $ddl."\n\n".
            $body."\n";
    }

    private function copy(string $table, string $location, string $iamRole, ExportFormat $format): string
    {
        $formatClause = $format === ExportFormat::Ndjson
            ? "FORMAT AS JSON 'auto ignorecase'"
            : 'FORMAT AS CSV IGNOREHEADER 1';

        return "COPY {$table}\n".
            "FROM '{$location}'\n".
            "IAM_ROLE '{$iamRole}'\n".
            "  {$formatClause}\n".
            "  TIMEFORMAT 'auto'\n".
            "  REGION '<aws-region>';";
    }

    /**
     * @param  list<ExportColumn>  $schema
     */
    private function upsert(ExportDataset $dataset, string $table, string $location, string $iamRole, ExportFormat $format, array $schema): string
    {
        $stageTable = $table.'_stage';
        $insertCols = $this->columnNames($schema);

        return "BEGIN TRANSACTION;\n\n".
            "CREATE TEMPORARY TABLE {$stageTable} (LIKE {$table});\n\n".
            $this->copy($stageTable, $location, $iamRole, $format)."\n\n".
            "DELETE FROM {$table} USING {$stageTable} s\n  WHERE ".$this->keyMatch($dataset, $table, 's').";\n\n".
            "INSERT INTO {$table} ({$insertCols})\n  SELECT {$insertCols} FROM {$stageTable};\n\n".
            'END TRANSACTION;';
    }
}
