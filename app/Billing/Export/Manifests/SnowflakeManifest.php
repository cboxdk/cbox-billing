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
 * Emits the real Snowflake load for a staged partition: the target-table DDL, a JSON/CSV file
 * format, and a `COPY INTO` from an external stage — plus, for an upsert dataset, the
 * stage-table + `MERGE` that replaces changed rows on the natural key, and for a snapshot the
 * `TRUNCATE` + `COPY`. This is how Snowflake bulk-loads (from staged files), so it is a
 * runnable artifact, not a stub.
 */
class SnowflakeManifest extends AbstractManifest
{
    public function warehouse(): Warehouse
    {
        return Warehouse::Snowflake;
    }

    public function generate(ExportDataset $dataset, WrittenPartition $partition, WarehouseTarget $target): string
    {
        $schema = $dataset->schema();
        $table = $target->table($dataset->key());
        $stage = $target->stage ?? '<snowflake-external-stage>';
        $location = '@'.$stage.'/'.$partition->directory().'/';
        $fileFormat = $this->fileFormat($partition->format);

        $ddl = "CREATE TABLE IF NOT EXISTS {$table} (\n".
            $this->columnDdl($schema, static fn (ExportColumn $c): string => $c->type->snowflake()).
            "\n);";

        $copyOptions = $partition->format === ExportFormat::Ndjson
            ? "FILE_FORMAT = (TYPE = JSON STRIP_OUTER_ARRAY = FALSE)\n  MATCH_BY_COLUMN_NAME = CASE_INSENSITIVE"
            : "FILE_FORMAT = (TYPE = CSV SKIP_HEADER = 1 FIELD_OPTIONALLY_ENCLOSED_BY = '\"' EMPTY_FIELD_AS_NULL = TRUE)";

        $body = match ($partition->syncMode) {
            SyncMode::Append => $this->copy($table, $location, $copyOptions),
            SyncMode::Snapshot => "TRUNCATE TABLE {$table};\n\n".$this->copy($table, $location, $copyOptions),
            SyncMode::Upsert => $this->upsert($dataset, $table, $location, $copyOptions, $schema),
        };

        return $this->banner('Snowflake', $dataset, $partition)."\n\n".
            $fileFormat."\n\n".
            $ddl."\n\n".
            $body."\n";
    }

    private function fileFormat(ExportFormat $format): string
    {
        return $format === ExportFormat::Ndjson
            ? '-- NDJSON is loaded with a JSON file format (one object per line).'
            : '-- CSV is loaded with a header-skipping CSV file format.';
    }

    private function copy(string $table, string $location, string $options): string
    {
        return "COPY INTO {$table}\nFROM {$location}\n  {$options}\n  ON_ERROR = ABORT_STATEMENT;";
    }

    /**
     * @param  list<ExportColumn>  $schema
     */
    private function upsert(ExportDataset $dataset, string $table, string $location, string $options, array $schema): string
    {
        $stageTable = $table.'_stage';
        $columns = $schema;

        $updates = implode(",\n    ", array_map(
            static fn (ExportColumn $c): string => "t.{$c->name} = s.{$c->name}",
            $columns,
        ));

        $insertCols = $this->columnNames($columns);
        $insertVals = implode(', ', array_map(static fn (ExportColumn $c): string => 's.'.$c->name, $columns));

        return "CREATE TEMPORARY TABLE {$stageTable} LIKE {$table};\n\n".
            $this->copy($stageTable, $location, $options)."\n\n".
            "MERGE INTO {$table} AS t\n".
            "USING {$stageTable} AS s\n".
            "  ON {$this->keyMatch($dataset)}\n".
            "WHEN MATCHED THEN UPDATE SET\n    {$updates}\n".
            "WHEN NOT MATCHED THEN INSERT ({$insertCols})\n  VALUES ({$insertVals});";
    }
}
