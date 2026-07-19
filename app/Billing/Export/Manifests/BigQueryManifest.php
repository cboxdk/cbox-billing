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
 * Emits the real BigQuery load for a staged partition: a `bq load` from the staged GCS files
 * with an inline schema — appending for the event stream, replacing for a snapshot, and for an
 * upsert loading into a `_stage` table then a `MERGE` (`bq query`) that replaces changed rows on
 * the natural key. This mirrors how BigQuery ingests object-storage files at scale.
 */
class BigQueryManifest extends AbstractManifest
{
    public function warehouse(): Warehouse
    {
        return Warehouse::BigQuery;
    }

    public function generate(ExportDataset $dataset, WrittenPartition $partition, WarehouseTarget $target): string
    {
        $schema = $dataset->schema();
        $table = $target->table($dataset->key());
        $stageTable = $table.'_stage';
        $uri = rtrim($target->externalBase, '/').'/'.$partition->directory().'/*.'.$partition->format->extension();
        $inlineSchema = $this->inlineSchema($schema);
        $sourceFlags = $this->sourceFlags($partition->format);

        return match ($partition->syncMode) {
            SyncMode::Append => $this->banner('BigQuery', $dataset, $partition)."\n\n".
                $this->load($table, $uri, $inlineSchema, $sourceFlags, replace: false),
            SyncMode::Snapshot => $this->banner('BigQuery', $dataset, $partition)."\n\n".
                $this->load($table, $uri, $inlineSchema, $sourceFlags, replace: true),
            SyncMode::Upsert => $this->banner('BigQuery', $dataset, $partition)."\n\n".
                $this->load($stageTable, $uri, $inlineSchema, $sourceFlags, replace: true)."\n\n".
                $this->merge($dataset, $table, $stageTable, $schema),
        }."\n";
    }

    /**
     * BigQuery's inline `field:TYPE,field:TYPE` schema shorthand.
     *
     * @param  list<ExportColumn>  $schema
     */
    private function inlineSchema(array $schema): string
    {
        return implode(',', array_map(
            static fn (ExportColumn $c): string => $c->name.':'.$c->type->bigQuery(),
            $schema,
        ));
    }

    private function sourceFlags(ExportFormat $format): string
    {
        return $format === ExportFormat::Ndjson
            ? '--source_format=NEWLINE_DELIMITED_JSON'
            : '--source_format=CSV --skip_leading_rows=1';
    }

    private function load(string $table, string $uri, string $schema, string $sourceFlags, bool $replace): string
    {
        return "bq load \\\n".
            '  '.$sourceFlags.' \\'."\n".
            '  --replace='.($replace ? 'true' : 'false').' \\'."\n".
            '  '.$table.' \\'."\n".
            '  "'.$uri.'" \\'."\n".
            '  '.$schema;
    }

    /**
     * @param  list<ExportColumn>  $schema
     */
    private function merge(ExportDataset $dataset, string $table, string $stageTable, array $schema): string
    {
        $updates = implode(",\n    ", array_map(
            static fn (ExportColumn $c): string => "t.{$c->name} = s.{$c->name}",
            $schema,
        ));

        $insertCols = $this->columnNames($schema);
        $insertVals = implode(', ', array_map(static fn (ExportColumn $c): string => 's.'.$c->name, $schema));

        $sql = "MERGE INTO {$table} AS t\n".
            "USING {$stageTable} AS s\n".
            "  ON {$this->keyMatch($dataset)}\n".
            "WHEN MATCHED THEN UPDATE SET\n    {$updates}\n".
            "WHEN NOT MATCHED THEN INSERT ({$insertCols})\n  VALUES ({$insertVals});";

        return "bq query --use_legacy_sql=false '".str_replace("'", "\\'", $sql)."'";
    }
}
