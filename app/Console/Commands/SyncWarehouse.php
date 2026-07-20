<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\WarehouseSyncService;
use App\Billing\Mode\EnvironmentScope;
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
     * The sinks to sync: one by key when `--sink` is given, else every enabled sink — across ALL
     * planes. The scheduled command runs in the ambient (production) plane, but a sink is
     * operator-infra of the plane it was created in, and each sink partitions/scopes the export by
     * its OWN environment (see {@see WarehouseSyncService}); so the selection must bypass the
     * {@see EnvironmentScope}, or a named sandbox's sink would never be synced.
     *
     * @return list<WarehouseSink>
     */
    private function sinks(): array
    {
        $key = $this->option('sink');

        $query = WarehouseSink::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('enabled', true);

        if (is_string($key) && $key !== '') {
            $query->where('key', $key);
        }

        return array_values($query->orderBy('id')->get()->all());
    }
}
