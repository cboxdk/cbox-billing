<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\WarehouseSyncService;
use App\Models\WarehouseSink;
use App\Models\WarehouseSyncRun;
use Illuminate\Console\Command;

/**
 * The incremental warehouse-sync pass: for every enabled sink (or one named sink), stage the
 * rows added/changed since each dataset's last watermark and write the per-warehouse load
 * manifest. Idempotent and cursor-driven — running it more often only tightens freshness, never
 * duplicates a delivered row. A thin adapter over {@see WarehouseSyncService}.
 */
class SyncWarehouse extends Command
{
    protected $signature = 'warehouse:sync {--sink= : Only sync the sink with this key} {--dataset= : Only sync this dataset}';

    protected $description = 'Stage incremental dataset partitions to configured warehouse sinks and emit load manifests.';

    public function handle(WarehouseSyncService $service): int
    {
        $sinks = $this->sinks();

        if ($sinks === []) {
            $this->info('No enabled warehouse sinks to sync.');

            return self::SUCCESS;
        }

        $dataset = $this->option('dataset');
        $dataset = is_string($dataset) && $dataset !== '' ? $dataset : null;

        $failures = 0;

        foreach ($sinks as $sink) {
            $runs = $dataset === null
                ? $service->syncSink($sink)
                : [$service->syncDataset($sink, app(DatasetRegistry::class)->get($dataset))];

            foreach ($runs as $run) {
                if ($run->status === WarehouseSyncRun::STATUS_FAILED) {
                    $failures++;
                    $this->error(sprintf('[%s] %s failed: %s', $sink->key, $run->dataset, (string) $run->error));

                    continue;
                }

                $this->line(sprintf(
                    '[%s] %s — %s · %d rows · %d bytes%s',
                    $sink->key,
                    $run->dataset,
                    $run->status,
                    (int) $run->rows,
                    (int) $run->bytes,
                    is_string($run->partition_path) ? ' · '.$run->partition_path : '',
                ));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The sinks to sync: one by key when `--sink` is given, else every enabled sink.
     *
     * @return list<WarehouseSink>
     */
    private function sinks(): array
    {
        $key = $this->option('sink');

        $query = WarehouseSink::query()->where('enabled', true);

        if (is_string($key) && $key !== '') {
            $query->where('key', $key);
        }

        return array_values($query->orderBy('id')->get()->all());
    }
}
