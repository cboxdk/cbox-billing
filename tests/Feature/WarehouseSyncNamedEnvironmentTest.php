<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\WarehouseSink;
use App\Models\WarehouseSyncRun;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Finding 4 (P2) — a named-sandbox warehouse sink exports THAT sandbox's data (partitioned/scoped by
 * its own environment key, not the binary livemode that would collapse it to the default sandbox),
 * and the SCHEDULED `warehouse:sync` command — which runs in the ambient production plane — still
 * processes it (the sink selection must span every plane, not just production).
 */
class WarehouseSyncNamedEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    private function invoiceIn(string $key, string $number): void
    {
        $this->inEnvironment($key, function () use ($key, $number): void {
            Organization::query()->firstOrCreate(['id' => 'org_'.$key], ['name' => $key, 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

            Invoice::query()->create([
                'organization_id' => 'org_'.$key, 'seller' => 'seller_x', 'number' => $number, 'currency' => 'DKK',
                'subtotal_minor' => 10_000, 'tax_minor' => 2_500, 'total_minor' => 12_500,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
            ]);
        });
    }

    private function sinkIn(string $key): void
    {
        $this->inEnvironment($key, function () use ($key): void {
            WarehouseSink::query()->create([
                'key' => 'sink_'.$key, 'name' => 'Sink '.$key, 'warehouse' => 'none', 'disk' => 'wh',
                'prefix' => 'export', 'format' => 'ndjson', 'livemode' => false,
                'datasets' => ['invoices'], 'enabled' => true,
            ]);
        });
    }

    public function test_the_scheduled_command_syncs_a_named_sandbox_sink_under_its_own_plane(): void
    {
        Storage::fake('wh');

        // A named sandbox cloned from production so it has the catalog; give it one invoice + a sink.
        app(CreatesEnvironments::class)->create(key: 'sbx-wh', cloneFrom: Environment::query()->where('key', 'production')->firstOrFail());
        $this->invoiceIn('sbx-wh', 'INV-SBX-1');
        $this->sinkIn('sbx-wh');

        // The scheduled command runs in the ambient PRODUCTION plane. It must still find and sync the
        // sandbox's sink (selection spans all planes) and export the sandbox's own invoice.
        $this->artisan('warehouse:sync')->assertSuccessful();

        $sink = WarehouseSink::query()->withoutGlobalScopes()->where('key', 'sink_sbx-wh')->firstOrFail();
        $run = WarehouseSyncRun::query()->where('sink_id', $sink->id)->where('dataset', 'invoices')->firstOrFail();

        // Exactly the one sandbox invoice was exported — proving it ran under 'sbx-wh', not the empty
        // default sandbox (which would have shipped 0 rows) and not production.
        $this->assertSame(1, (int) $run->rows);
        $this->assertSame(WarehouseSyncRun::STATUS_SUCCESS, $run->status);
    }
}
