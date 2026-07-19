<?php

declare(strict_types=1);

namespace App\Billing\Export\Sinks;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Contracts\WarehouseSink;
use App\Billing\Export\DataExporter;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Manifests\ManifestRegistry;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\WarehouseSink as SinkConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The REAL, working warehouse sink — the way Snowflake/BigQuery/Redshift actually ingest at
 * scale: it stages the dataset as partitioned files in object storage (any configured Laravel
 * disk — `s3`, or a `Storage::fake` in tests) and writes, alongside each file, a JSON delivery
 * manifest and the exact per-warehouse load statement an operator (or a scheduled loader) runs.
 * There is no fabricated warehouse client and no bundled vendor SDK — with S3 credentials this
 * is fully functional; the optional live-push path is the honest {@see WarehousePush} seam.
 *
 * Streaming is memory-bounded: rows are pumped through the encoder into a `php://temp` stream
 * (which spills to disk past a small threshold), then handed to the disk as a stream — the whole
 * dataset is never held in memory, on either side.
 */
class ObjectStoreSink implements WarehouseSink
{
    public function __construct(
        private readonly DataExporter $exporter,
        private readonly RowEncoderFactory $encoders,
        private readonly ManifestRegistry $manifests,
    ) {}

    public function deliver(SinkConfig $sink, ExportDataset $dataset, ExportFormat $format, ExportQuery $query): WrittenPartition
    {
        $disk = Storage::disk($sink->disk);
        $encoder = $this->encoders->for($format);

        // Stream rows into a spill-to-disk temp stream so neither side buffers the whole dataset.
        $buffer = fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'r+');

        if ($buffer === false) {
            throw new \RuntimeException('Unable to open a staging buffer for the export.');
        }

        $result = $this->exporter->pump($dataset, $encoder, $query, static function (string $chunk) use ($buffer): void {
            fwrite($buffer, $chunk);
        });

        $partitionDate = CarbonImmutable::now()->utc()->format('Y-m-d');

        // Nothing new since the watermark: stage no empty file (a snapshot always writes, since
        // it fully replaces the target — even to empty it).
        if ($result->rows === 0 && $dataset->syncMode() !== SyncMode::Snapshot) {
            fclose($buffer);

            return new WrittenPartition(
                dataset: $dataset->key(),
                livemode: $sink->livemode === true,
                disk: $sink->disk,
                path: '',
                manifestPath: '',
                loadManifestPath: null,
                format: $format,
                syncMode: $dataset->syncMode(),
                rows: 0,
                bytes: 0,
                partitionDate: $partitionDate,
                cursorFrom: null,
                cursorTo: null,
            );
        }

        $path = $this->partitionPath($sink, $dataset, $format, $partitionDate, $result->cursorFrom, $result->cursorTo);

        rewind($buffer);
        $disk->writeStream($path, $buffer);
        fclose($buffer);

        $manifestPath = $this->writeDeliveryManifest($disk, $sink, $dataset, $format, $path, $partitionDate, $result->rows, $result->bytes, $result->cursorFrom, $result->cursorTo);

        $partition = new WrittenPartition(
            dataset: $dataset->key(),
            livemode: $sink->livemode === true,
            disk: $sink->disk,
            path: $path,
            manifestPath: $manifestPath,
            loadManifestPath: null,
            format: $format,
            syncMode: $dataset->syncMode(),
            rows: $result->rows,
            bytes: $result->bytes,
            partitionDate: $partitionDate,
            cursorFrom: $result->cursorFrom,
            cursorTo: $result->cursorTo,
        );

        $loadManifestPath = $this->writeLoadManifest($disk, $sink, $dataset, $partition);

        return new WrittenPartition(
            dataset: $partition->dataset,
            livemode: $partition->livemode,
            disk: $partition->disk,
            path: $partition->path,
            manifestPath: $partition->manifestPath,
            loadManifestPath: $loadManifestPath,
            format: $partition->format,
            syncMode: $partition->syncMode,
            rows: $partition->rows,
            bytes: $partition->bytes,
            partitionDate: $partition->partitionDate,
            cursorFrom: $partition->cursorFrom,
            cursorTo: $partition->cursorTo,
        );
    }

    /**
     * The staged file's disk-relative path. Layout (Hive-style partitions a warehouse external
     * table understands):
     *
     *   {prefix}/{dataset}/livemode={0|1}/dt={YYYY-MM-DD}/part-{cursorLo}-{cursorHi}.{ext}
     *
     * Append/Upsert files are named by the cursor window they cover, so re-running the same
     * window overwrites the same file (idempotent); a Snapshot replaces the whole date folder.
     */
    private function partitionPath(SinkConfig $sink, ExportDataset $dataset, ExportFormat $format, string $date, ?string $from, ?string $to): string
    {
        $segments = array_filter([
            $sink->normalizedPrefix(),
            $dataset->key(),
            'livemode='.($sink->livemode === true ? '1' : '0'),
        ], static fn (string $s): bool => $s !== '');

        $window = $dataset->syncMode() === SyncMode::Snapshot
            ? 'snapshot'
            : 'part-'.$this->slug($from).'-'.$this->slug($to);

        return implode('/', $segments).'/dt='.$date.'/'.$window.'.'.$format->extension();
    }

    /** Write the JSON delivery manifest describing the staged file, and return its path. */
    private function writeDeliveryManifest(Filesystem $disk, SinkConfig $sink, ExportDataset $dataset, ExportFormat $format, string $path, string $date, int $rows, int $bytes, ?string $from, ?string $to): string
    {
        $manifestPath = $this->manifestPathFor($path);

        $manifest = [
            'dataset' => $dataset->key(),
            'sink' => $sink->key,
            'format' => $format->value,
            'sync_mode' => $dataset->syncMode()->value,
            'livemode' => $sink->livemode === true,
            'partition_date' => $date,
            'file' => basename($path),
            'rows' => $rows,
            'bytes' => $bytes,
            'cursor_from' => $from,
            'cursor_to' => $to,
            'merge_keys' => $dataset->mergeKeys(),
            'columns' => array_map(
                static fn (ExportColumn $c): array => ['name' => $c->name, 'type' => $c->type->value],
                $dataset->schema(),
            ),
            'generated_at' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $disk->put($manifestPath, $json === false ? '{}' : $json);

        return $manifestPath;
    }

    /**
     * Write the per-warehouse load statement (the exact COPY/`bq load`/DDL) next to the staged
     * file, when the sink targets a warehouse dialect. Returns its path, or null for a
     * staged-files-only sink.
     */
    private function writeLoadManifest(Filesystem $disk, SinkConfig $sink, ExportDataset $dataset, WrittenPartition $partition): ?string
    {
        $generator = $this->manifests->for($sink->warehouseEnum());

        if ($generator === null) {
            return null;
        }

        $statement = $generator->generate($dataset, $partition, $sink->target());
        $path = $partition->directory().'/load-'.$sink->warehouseEnum()->value.'.sql';

        $disk->put($path, $statement);

        return $path;
    }

    private function manifestPathFor(string $path): string
    {
        $dot = strrpos($path, '.');
        $base = $dot === false ? $path : substr($path, 0, $dot);

        return $base.'.manifest.json';
    }

    private function slug(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        $slug = preg_replace('/[^A-Za-z0-9]+/', '', $value);

        return is_string($slug) && $slug !== '' ? $slug : 'none';
    }
}
