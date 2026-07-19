<?php

declare(strict_types=1);

namespace App\Billing\Export;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Manifests\ManifestRegistry;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\WarehouseSink as SinkConfig;
use Carbon\CarbonImmutable;

/**
 * Renders the load manifest a sink WOULD emit for a dataset, without staging anything — so the
 * console can show an operator the exact `COPY`/`bq load`/DDL to wire the load side before a
 * single sync has run. It builds a representative partition (an example path and cursor window)
 * and phrases it through the sink's warehouse dialect.
 */
class WarehouseManifestPreview
{
    public function __construct(private readonly ManifestRegistry $manifests) {}

    /**
     * The load manifest text for `$dataset` under `$sink`, or null when the sink stages files
     * only (no warehouse dialect selected).
     */
    public function for(SinkConfig $sink, ExportDataset $dataset): ?string
    {
        $generator = $this->manifests->for($sink->warehouseEnum());

        if ($generator === null) {
            return null;
        }

        $date = CarbonImmutable::now()->utc()->format('Y-m-d');
        $prefix = $sink->normalizedPrefix();
        $plane = $sink->livemode === true ? '1' : '0';
        $ext = $sink->formatEnum()->extension();

        $directory = trim(($prefix !== '' ? $prefix.'/' : '').$dataset->key().'/livemode='.$plane.'/dt='.$date, '/');
        $path = $directory.'/part-example.'.$ext;

        $partition = new WrittenPartition(
            dataset: $dataset->key(),
            livemode: $sink->livemode === true,
            disk: $sink->disk,
            path: $path,
            manifestPath: $directory.'/part-example.manifest.json',
            loadManifestPath: null,
            format: $sink->formatEnum(),
            syncMode: $dataset->syncMode(),
            rows: 0,
            bytes: 0,
            partitionDate: $date,
            cursorFrom: null,
            cursorTo: null,
        );

        return $generator->generate($dataset, $partition, $sink->target());
    }
}
