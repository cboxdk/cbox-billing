<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SubscriptionMrrMovement;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The export streaming contract on real rows: exact CSV headers + values and exact NDJSON
 * objects for the highest-value datasets (invoices, MRR movements, the raw usage-event stream),
 * with types preserved (integers stay numbers, timestamps ISO-8601).
 */
class DataExportStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::create(['id' => 'org_live', 'name' => 'Live Org', 'billing_email' => 'live@example.test']);

        Invoice::create([
            'organization_id' => 'org_live',
            'seller' => 'cbox-dk',
            'number' => 'INV-900',
            'currency' => 'DKK',
            'subtotal_minor' => 10000,
            'tax_minor' => 2500,
            'total_minor' => 12500,
            'status' => 'paid',
            'issued_at' => Carbon::parse('2026-06-15 10:00:00'),
            'paid_at' => Carbon::parse('2026-06-16 09:00:00'),
            'gateway_reference' => 'pay_abc',
        ]);

        SubscriptionMrrMovement::create([
            'subscription_id' => null,
            'organization_id' => 'org_live',
            'currency' => 'DKK',
            'occurred_at' => Carbon::parse('2026-06-10 12:00:00'),
            'previous_mrr_minor' => 29000,
            'new_mrr_minor' => 124000,
            'kind' => SubscriptionMrrMovement::KIND_EXPANSION,
        ]);

        app(EventLog::class)->append([
            new UsageEvent('evt-1', 'org_live', 'api.requests', 'edge', 4200, 1_700_000_000_000, null, 1),
            new UsageEvent('evt-2', 'org_live', 'api.requests', 'edge', 800, 1_700_000_050_000, 'req-9', 3),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function ndjson(string $dataset, ?ExportQuery $query = null): array
    {
        $out = '';
        app(DataExporter::class)->pump(
            app(DatasetRegistry::class)->get($dataset),
            app(RowEncoderFactory::class)->for(ExportFormat::Ndjson),
            $query ?? ExportQuery::plane('production', true),
            function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
        );

        $rows = [];
        foreach (array_filter(explode("\n", $out), static fn (string $l): bool => $l !== '') as $line) {
            $decoded = json_decode($line, true);
            $rows[] = is_array($decoded) ? $decoded : [];
        }

        return $rows;
    }

    /** @return list<string> */
    private function csvLines(string $dataset): array
    {
        $out = '';
        app(DataExporter::class)->pump(
            app(DatasetRegistry::class)->get($dataset),
            app(RowEncoderFactory::class)->for(ExportFormat::Csv),
            ExportQuery::plane('production', true),
            function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
        );

        return array_values(array_filter(preg_split("/\r\n/", $out) ?: [], static fn (string $l): bool => $l !== ''));
    }

    public function test_invoices_csv_has_exact_header_and_row(): void
    {
        $lines = $this->csvLines('invoices');

        $this->assertSame(
            'id,number,organization_id,subscription_id,seller,currency,subtotal_minor,tax_minor,total_minor,status,period_start,period_end,issued_at,due_at,paid_at,gateway_reference,livemode,created_at,updated_at',
            $lines[0],
        );

        // The one seeded invoice, exact values (money as integer minor units; livemode true).
        $this->assertStringContainsString('INV-900,org_live,,cbox-dk,DKK,10000,2500,12500,paid,', $lines[1]);
        $this->assertStringContainsString('2026-06-15T10:00:00Z', $lines[1]);
        $this->assertStringContainsString('pay_abc,true,', $lines[1]);
    }

    public function test_invoices_ndjson_preserves_types(): void
    {
        $rows = $this->ndjson('invoices');

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('INV-900', $row['number']);
        $this->assertSame(10000, $row['subtotal_minor']);
        $this->assertSame(2500, $row['tax_minor']);
        $this->assertSame(12500, $row['total_minor']);
        $this->assertSame('paid', $row['status']);
        $this->assertTrue($row['livemode']);
        $this->assertSame('2026-06-15T10:00:00Z', $row['issued_at']);
        $this->assertNull($row['subscription_id']);
    }

    public function test_mrr_movements_ndjson_has_signed_delta(): void
    {
        $rows = $this->ndjson('mrr_movements');

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('expansion', $row['kind']);
        $this->assertSame(29000, $row['previous_mrr_minor']);
        $this->assertSame(124000, $row['new_mrr_minor']);
        $this->assertSame(95000, $row['delta_mrr_minor']);
        $this->assertSame('org_live', $row['organization_id']);
    }

    public function test_usage_events_ndjson_is_the_raw_stream(): void
    {
        $rows = $this->ndjson('usage_events');

        $this->assertCount(2, $rows);

        $this->assertSame('evt-1', $rows[0]['event_id']);
        $this->assertSame('api.requests', $rows[0]['meter']);
        $this->assertSame(4200, $rows[0]['value']);
        $this->assertSame(1, $rows[0]['weight']);
        $this->assertNull($rows[0]['unique_key']);
        $this->assertSame(1_700_000_000_000, $rows[0]['occurred_at_ms']);
        // The millisecond epoch is also rendered ISO-8601 UTC.
        $this->assertSame('2023-11-14T22:13:20.000Z', $rows[0]['occurred_at']);

        $this->assertSame('req-9', $rows[1]['unique_key']);
        $this->assertSame(3, $rows[1]['weight']);
    }
}
