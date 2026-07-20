<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Models\Organization;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Streaming is memory-bounded: the exporter reads the table in CHUNKS (keyset cursor), issuing
 * one query per chunk rather than a single query that loads every row. Asserted by counting the
 * SELECTs against the event table for a dataset larger than the chunk size.
 */
class ExportStreamingBoundedTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_reads_in_chunks_not_one_shot(): void
    {
        config()->set('billing.export.chunk_size', 10);

        Organization::create(['id' => 'org_live', 'name' => 'Live', 'billing_email' => 'l@example.test']);

        $events = [];
        for ($i = 0; $i < 25; $i++) {
            $events[] = new UsageEvent('evt-'.$i, 'org_live', 'm', 's', 1, 1_700_000_000_000 + $i);
        }
        app(EventLog::class)->append($events);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'billing_usage_events') && str_starts_with(trim(strtolower($query->sql)), 'select')) {
                $queries++;
            }
        });

        $rows = 0;
        app(DataExporter::class)->pump(
            app(DatasetRegistry::class)->get('usage_events'),
            app(RowEncoderFactory::class)->for(ExportFormat::Ndjson),
            ExportQuery::plane('production', true),
            function () use (&$rows): void {
                $rows++;
            },
        );

        $this->assertSame(25, $rows);
        // 25 rows / chunk 10 → at least 3 chunked SELECTs; crucially more than a single one-shot load.
        $this->assertGreaterThanOrEqual(3, $queries);
    }
}
