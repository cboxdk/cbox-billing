<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Models\Invoice;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Plane isolation and date-range scoping: a live export never contains a test-plane row (or a
 * test org's usage event), and an inclusive date range selects only rows in the window.
 */
class ExportScopingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function ndjson(string $dataset, ExportQuery $query): array
    {
        $out = '';
        app(DataExporter::class)->pump(
            app(DatasetRegistry::class)->get($dataset),
            app(RowEncoderFactory::class)->for(ExportFormat::Ndjson),
            $query,
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

    private function inTestPlane(callable $fn): void
    {
        $context = app(BillingContext::class);
        $context->setMode(BillingMode::Test);

        try {
            $fn();
        } finally {
            $context->setMode(BillingMode::Live);
        }
    }

    public function test_live_export_excludes_test_plane_rows(): void
    {
        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);
        Invoice::create(['organization_id' => 'org_live', 'seller' => 's', 'number' => 'LIVE-1', 'currency' => 'DKK', 'subtotal_minor' => 100, 'tax_minor' => 0, 'total_minor' => 100, 'status' => 'paid', 'issued_at' => Carbon::parse('2026-06-01')]);

        $this->inTestPlane(function (): void {
            Organization::create(['id' => 'org_test', 'name' => 'Test', 'billing_email' => 't@example.test']);
            Invoice::create(['organization_id' => 'org_test', 'seller' => 's', 'number' => 'TEST-1', 'currency' => 'DKK', 'subtotal_minor' => 200, 'tax_minor' => 0, 'total_minor' => 200, 'status' => 'paid', 'issued_at' => Carbon::parse('2026-06-01')]);
        });

        $live = $this->ndjson('invoices', ExportQuery::plane(true));
        $this->assertCount(1, $live);
        $this->assertSame('LIVE-1', $live[0]['number']);
        $this->assertTrue($live[0]['livemode']);

        $test = $this->ndjson('invoices', ExportQuery::plane(false));
        $this->assertCount(1, $test);
        $this->assertSame('TEST-1', $test[0]['number']);
        $this->assertFalse($test[0]['livemode']);
    }

    public function test_usage_event_stream_is_plane_scoped_by_org(): void
    {
        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);
        $this->inTestPlane(function (): void {
            Organization::create(['id' => 'org_test', 'name' => 'Test', 'billing_email' => 't@example.test']);
        });

        app(EventLog::class)->append([
            new UsageEvent('live-evt', 'org_live', 'm', 's', 10, 1_700_000_000_000),
            new UsageEvent('test-evt', 'org_test', 'm', 's', 20, 1_700_000_000_000),
        ]);

        $live = $this->ndjson('usage_events', ExportQuery::plane(true));
        $this->assertSame(['live-evt'], array_column($live, 'event_id'));

        $test = $this->ndjson('usage_events', ExportQuery::plane(false));
        $this->assertSame(['test-evt'], array_column($test, 'event_id'));
    }

    public function test_date_range_filters_inclusively(): void
    {
        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);

        foreach (['2026-05-15', '2026-06-15', '2026-07-15'] as $i => $date) {
            Invoice::create(['organization_id' => 'org_live', 'seller' => 's', 'number' => 'INV-'.$i, 'currency' => 'DKK', 'subtotal_minor' => 100, 'tax_minor' => 0, 'total_minor' => 100, 'status' => 'paid', 'issued_at' => Carbon::parse($date.' 12:00:00')]);
        }

        $rows = $this->ndjson('invoices', ExportQuery::window(
            true,
            CarbonImmutable::parse('2026-06-01')->startOfDay(),
            CarbonImmutable::parse('2026-06-30')->endOfDay(),
        ));

        $this->assertSame(['INV-1'], array_column($rows, 'number'));
    }
}
