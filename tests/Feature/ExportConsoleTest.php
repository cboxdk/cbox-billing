<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\WarehouseSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Exports + Warehouse console area on real rows: the dataset picker renders, a download
 * streams the correct CSV, and a sink can be configured, run, and its load manifest inspected.
 */
class ExportConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);
        Invoice::create([
            'organization_id' => 'org_live', 'seller' => 'cbox-dk', 'number' => 'INV-900', 'currency' => 'DKK',
            'subtotal_minor' => 10000, 'tax_minor' => 2500, 'total_minor' => 12500, 'status' => 'paid',
            'issued_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
    }

    public function test_exports_index_lists_datasets(): void
    {
        $this->withSession($this->session)->get('/exports')
            ->assertOk()
            ->assertSee('Data exports')
            ->assertSee('Usage events (raw)')
            ->assertSee('MRR movements');
    }

    public function test_download_streams_csv_with_header_and_row(): void
    {
        $response = $this->withSession($this->session)->get('/exports/download?dataset=invoices&format=csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('number,organization_id', $body);
        $this->assertStringContainsString('INV-900', $body);
        $this->assertStringContainsString('12500', $body);
    }

    public function test_download_rejects_unknown_dataset(): void
    {
        $this->withSession($this->session)->get('/exports/download?dataset=nope&format=csv')
            ->assertSessionHasErrors('dataset');
    }

    public function test_warehouse_index_renders(): void
    {
        $this->withSession($this->session)->get('/exports/warehouse')
            ->assertOk()
            ->assertSee('Warehouse sinks');
    }

    public function test_operator_can_configure_run_and_inspect_a_sink(): void
    {
        Storage::fake('wh');

        // Create a sink.
        $this->withSession($this->session)->post('/exports/warehouse', [
            'key' => 'analytics', 'name' => 'Analytics', 'warehouse' => 'snowflake', 'disk' => 'wh',
            'prefix' => 'billing/export', 'format' => 'ndjson', 'livemode' => '1',
            'datasets' => ['invoices'], 'target_schema' => 'analytics_billing', 'target_stage' => 'BILLING_STAGE',
        ])->assertRedirect(route('billing.exports.warehouse'));

        $sink = WarehouseSink::query()->where('key', 'analytics')->firstOrFail();
        $this->assertSame(['invoices'], $sink->datasetKeys());
        $this->assertTrue($sink->livemode);

        // Run it now.
        $this->withSession($this->session)->post(route('billing.exports.warehouse.run', $sink))
            ->assertRedirect(route('billing.exports.warehouse'))
            ->assertSessionHas('status');

        $this->assertNotEmpty(Storage::disk('wh')->allFiles());

        // Inspect the generated load manifest.
        $this->withSession($this->session)->get(route('billing.exports.warehouse.manifest', ['warehouseSink' => $sink, 'dataset' => 'invoices']))
            ->assertOk()
            ->assertSee('MERGE INTO analytics_billing.invoices', false)
            ->assertSee('COPY INTO', false);
    }

    public function test_manifest_route_404s_for_unknown_dataset(): void
    {
        $sink = WarehouseSink::create([
            'key' => 's', 'name' => 'S', 'warehouse' => 'snowflake', 'disk' => 'wh', 'prefix' => '',
            'format' => 'ndjson', 'livemode' => true, 'datasets' => ['invoices'], 'enabled' => true,
        ]);

        $this->withSession($this->session)->get('/exports/warehouse/'.$sink->id.'/manifest/nope')
            ->assertNotFound();
    }
}
