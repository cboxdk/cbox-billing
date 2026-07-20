<?php

declare(strict_types=1);

namespace App\Billing\Export;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Contracts\WarehousePush;
use App\Billing\Export\Contracts\WarehouseSink;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\WrittenPartition;
use App\Models\Environment;
use App\Models\WarehouseSink as SinkConfig;
use App\Models\WarehouseSyncCursor;
use App\Models\WarehouseSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates one incremental sync: for each dataset a sink delivers, it reads the per-(sink,
 * dataset) watermark, streams only the rows past it into a staged partition via the
 * {@see WarehouseSink}, advances the watermark to the highest cursor delivered, writes the
 * delivery/run-log row, and invokes the (default no-op) direct-push seam. A watermark advance
 * and its run-log row commit together, so a crash mid-sync never advances past un-logged data.
 *
 * Append/upsert datasets carry a watermark (only new/changed rows ship); a snapshot dataset
 * carries none and full-refreshes each run.
 */
class WarehouseSyncService
{
    public function __construct(
        private readonly WarehouseSink $sink,
        private readonly DatasetRegistry $registry,
        private readonly WarehousePush $push,
    ) {}

    /**
     * Sync every registered dataset the sink delivers. Unknown dataset keys are skipped.
     *
     * @return list<WarehouseSyncRun>
     */
    public function syncSink(SinkConfig $sink): array
    {
        $runs = [];

        foreach ($sink->datasetKeys() as $key) {
            if (! $this->registry->has($key)) {
                continue;
            }

            $runs[] = $this->syncDataset($sink, $this->registry->get($key));
        }

        return $runs;
    }

    /** Sync a single dataset for a sink, returning its logged run. */
    public function syncDataset(SinkConfig $sink, ExportDataset $dataset): WarehouseSyncRun
    {
        $cursorRow = $this->cursorRow($sink, $dataset);
        $mode = $dataset->syncMode();
        $watermark = $mode->usesWatermark() ? $cursorRow->value() : null;

        // The sink's `livemode` field is the operator's choice of WHICH plane's data to export (the
        // external warehouse partition contract), so map it to the canonical plane key the datasets
        // now filter on — keeping environment and livemode consistent for the output partition.
        $query = ExportQuery::plane($sink->livemode ? Environment::PRODUCTION : Environment::SANDBOX, $sink->livemode === true)->after($watermark);
        $startedAt = CarbonImmutable::now();

        try {
            $partition = $this->sink->deliver($sink, $dataset, $sink->formatEnum(), $query);
        } catch (Throwable $e) {
            return $this->logFailure($sink, $dataset, $startedAt, $e->getMessage());
        }

        $this->push->push($sink, $partition);

        return DB::transaction(function () use ($sink, $dataset, $cursorRow, $mode, $partition, $startedAt): WarehouseSyncRun {
            $advance = [
                'cursor_kind' => $dataset->cursor()->kind->value,
                'rows_total' => max(0, (int) $cursorRow->rows_total + $partition->rows),
                'last_synced_at' => CarbonImmutable::now(),
            ];

            if ($mode->usesWatermark() && $partition->cursorTo !== null) {
                $advance['cursor_value'] = $partition->cursorTo;
            }

            $cursorRow->fill($advance)->save();

            return $this->logDelivery($sink, $dataset, $partition, $startedAt);
        });
    }

    private function cursorRow(SinkConfig $sink, ExportDataset $dataset): WarehouseSyncCursor
    {
        return WarehouseSyncCursor::firstOrNew(
            ['sink_id' => $sink->id, 'dataset' => $dataset->key()],
            ['cursor_kind' => $dataset->cursor()->kind->value, 'rows_total' => 0],
        );
    }

    private function logDelivery(SinkConfig $sink, ExportDataset $dataset, WrittenPartition $partition, CarbonImmutable $startedAt): WarehouseSyncRun
    {
        return WarehouseSyncRun::create([
            'sink_id' => $sink->id,
            'dataset' => $dataset->key(),
            'warehouse' => $sink->warehouseEnum()->value,
            'format' => $sink->formatEnum()->value,
            'sync_mode' => $dataset->syncMode()->value,
            'status' => $partition->rows === 0 ? WarehouseSyncRun::STATUS_EMPTY : WarehouseSyncRun::STATUS_SUCCESS,
            'partition_path' => $partition->path !== '' ? $partition->path : null,
            'manifest_path' => $partition->loadManifestPath ?? ($partition->manifestPath !== '' ? $partition->manifestPath : null),
            'rows' => max(0, $partition->rows),
            'bytes' => max(0, $partition->bytes),
            'cursor_from' => $partition->cursorFrom,
            'cursor_to' => $partition->cursorTo,
            'partition_date' => $partition->partitionDate,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
        ]);
    }

    private function logFailure(SinkConfig $sink, ExportDataset $dataset, CarbonImmutable $startedAt, string $message): WarehouseSyncRun
    {
        return WarehouseSyncRun::create([
            'sink_id' => $sink->id,
            'dataset' => $dataset->key(),
            'warehouse' => $sink->warehouseEnum()->value,
            'format' => $sink->formatEnum()->value,
            'sync_mode' => $dataset->syncMode()->value,
            'status' => WarehouseSyncRun::STATUS_FAILED,
            'rows' => 0,
            'bytes' => 0,
            'error' => $message,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
        ]);
    }
}
