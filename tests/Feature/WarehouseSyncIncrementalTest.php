<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\WarehouseSyncService;
use App\Models\Organization;
use App\Models\WarehouseSink;
use App\Models\WarehouseSyncCursor;
use App\Models\WarehouseSyncRun;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Incremental warehouse sync on the append-only event stream: the first run ships N, a second
 * run after appending M ships only M (the watermark advances), and an immediate re-run with no
 * new data ships nothing (idempotent — no duplicate delivery).
 */
class WarehouseSyncIncrementalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);
    }

    private function appendEvents(int $count, int $offset = 0): void
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $offset + $i;
            $events[] = new UsageEvent('evt-'.$n, 'org_live', 'm', 's', 1, 1_700_000_000_000 + $n);
        }
        app(EventLog::class)->append($events);
    }

    private function sink(): WarehouseSink
    {
        return WarehouseSink::create([
            'key' => 'wh', 'name' => 'WH', 'warehouse' => 'none', 'disk' => 'wh',
            'prefix' => 'export', 'format' => 'ndjson', 'livemode' => true,
            'datasets' => ['usage_events'], 'enabled' => true,
        ]);
    }

    public function test_incremental_sync_ships_only_new_rows_and_is_idempotent(): void
    {
        Storage::fake('wh');
        $sink = $this->sink();
        $service = app(WarehouseSyncService::class);

        // First run: 5 events.
        $this->appendEvents(5);
        $run1 = $service->syncDataset($sink, app(DatasetRegistry::class)->get('usage_events'));
        $this->assertSame(5, (int) $run1->rows);
        $this->assertSame(WarehouseSyncRun::STATUS_SUCCESS, $run1->status);

        $cursor = WarehouseSyncCursor::query()->where('sink_id', $sink->id)->where('dataset', 'usage_events')->firstOrFail();
        $this->assertSame('5', $cursor->cursor_value);
        $this->assertSame(5, (int) $cursor->rows_total);

        // Append 3 more, sync again: only the 3 new ones ship.
        $this->appendEvents(3, 5);
        $run2 = $service->syncDataset($sink, app(DatasetRegistry::class)->get('usage_events'));
        $this->assertSame(3, (int) $run2->rows);

        $cursor->refresh();
        $this->assertSame('8', $cursor->cursor_value);
        $this->assertSame(8, (int) $cursor->rows_total);

        // Immediate re-run with no new data: nothing ships (idempotent, no duplication).
        $run3 = $service->syncDataset($sink, app(DatasetRegistry::class)->get('usage_events'));
        $this->assertSame(0, (int) $run3->rows);
        $this->assertSame(WarehouseSyncRun::STATUS_EMPTY, $run3->status);

        $cursor->refresh();
        $this->assertSame('8', $cursor->cursor_value);

        // Across all runs, exactly 8 distinct events were staged (5 + 3 + 0), never re-delivered.
        $staged = array_sum(WarehouseSyncRun::query()->where('sink_id', $sink->id)->pluck('rows')->map(fn ($r): int => (int) $r)->all());
        $this->assertSame(8, $staged);
    }
}
