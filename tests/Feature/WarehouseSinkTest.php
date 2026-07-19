<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\WarehouseSyncService;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\WarehouseSink;
use App\Models\WarehouseSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The object-store sink stages a dataset as partitioned files with a JSON delivery manifest and
 * a per-warehouse load manifest, against a faked disk — the real ingestion pattern, exercised
 * without any warehouse SDK.
 */
class WarehouseSinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);

        foreach (['A', 'B'] as $i => $suffix) {
            Invoice::create([
                'organization_id' => 'org_live', 'seller' => 'cbox-dk', 'number' => 'INV-'.$suffix,
                'currency' => 'DKK', 'subtotal_minor' => 100 * ($i + 1), 'tax_minor' => 0, 'total_minor' => 100 * ($i + 1),
                'status' => 'paid', 'issued_at' => Carbon::parse('2026-06-1'.$i.' 10:00:00'),
            ]);
        }
    }

    private function sink(): WarehouseSink
    {
        return WarehouseSink::create([
            'key' => 's3', 'name' => 'Analytics', 'warehouse' => 'snowflake', 'disk' => 'wh',
            'prefix' => 'billing/export', 'format' => 'ndjson', 'livemode' => true,
            'datasets' => ['invoices'], 'target_schema' => 'analytics_billing', 'target_stage' => 'BILLING_STAGE',
            'enabled' => true,
        ]);
    }

    public function test_sink_stages_partitioned_files_manifest_and_load_manifest(): void
    {
        $disk = Storage::fake('wh');

        app(WarehouseSyncService::class)->syncSink($this->sink());

        $files = $disk->allFiles();

        $part = collect($files)->first(fn (string $f): bool => str_ends_with($f, '.ndjson'));
        $this->assertNotNull($part);
        $this->assertStringContainsString('billing/export/invoices/livemode=1/dt=', $part);

        // Two NDJSON objects, one per invoice.
        $lines = array_values(array_filter(explode("\n", $disk->get($part)), static fn (string $l): bool => $l !== ''));
        $this->assertCount(2, $lines);

        // The JSON delivery manifest sits alongside and describes the file.
        $manifestPath = collect($files)->first(fn (string $f): bool => str_ends_with($f, '.manifest.json'));
        $this->assertNotNull($manifestPath);
        $manifest = json_decode($disk->get($manifestPath), true);
        $this->assertSame(2, $manifest['rows']);
        $this->assertSame('invoices', $manifest['dataset']);
        $this->assertSame('upsert', $manifest['sync_mode']);
        $this->assertSame(['id'], $manifest['merge_keys']);
        $this->assertContains('total_minor', array_column($manifest['columns'], 'name'));

        // The per-warehouse load manifest is written next to the data.
        $load = collect($files)->first(fn (string $f): bool => str_ends_with($f, 'load-snowflake.sql'));
        $this->assertNotNull($load);
        $this->assertStringContainsString('MERGE INTO analytics_billing.invoices', $disk->get($load));
    }

    public function test_sink_run_is_logged(): void
    {
        Storage::fake('wh');
        $sink = $this->sink();

        app(WarehouseSyncService::class)->syncSink($sink);

        $run = WarehouseSyncRun::query()->where('sink_id', $sink->id)->where('dataset', 'invoices')->firstOrFail();
        $this->assertSame(WarehouseSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, (int) $run->rows);
        $this->assertGreaterThan(0, (int) $run->bytes);
        $this->assertNotNull($run->partition_path);
    }
}
