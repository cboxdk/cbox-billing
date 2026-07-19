<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Manifests\BigQueryManifest;
use App\Billing\Export\Manifests\RedshiftManifest;
use App\Billing\Export\Manifests\SnowflakeManifest;
use App\Billing\Export\ValueObjects\WarehouseTarget;
use App\Billing\Export\ValueObjects\WrittenPartition;
use Tests\TestCase;

/**
 * The per-warehouse load manifests emit the correct, runnable statement text for a staged
 * partition — a plain COPY/append for the event stream, a keyed MERGE/upsert for a dimension,
 * and a truncate-replace for a snapshot — with the exact staged location and column DDL.
 */
class WarehouseManifestTest extends TestCase
{
    private function partition(string $dataset, SyncMode $mode, ExportFormat $format = ExportFormat::Ndjson): WrittenPartition
    {
        return new WrittenPartition(
            dataset: $dataset,
            livemode: true,
            disk: 'wh',
            path: 'billing/'.$dataset.'/livemode=1/dt=2026-07-19/part-1-10.'.$format->extension(),
            manifestPath: 'billing/'.$dataset.'/livemode=1/dt=2026-07-19/part-1-10.manifest.json',
            loadManifestPath: null,
            format: $format,
            syncMode: $mode,
            rows: 10,
            bytes: 4096,
            partitionDate: '2026-07-19',
            cursorFrom: '1',
            cursorTo: '10',
        );
    }

    private function target(): WarehouseTarget
    {
        // externalBase is the disk/bucket ROOT; the staged prefix lives inside the partition path.
        return new WarehouseTarget(
            's3://analytics-bucket',
            'analytics_billing',
            'BILLING_STAGE',
            'arn:aws:iam::123456789012:role/redshift-load',
        );
    }

    public function test_snowflake_upsert_emits_copy_into_stage_and_merge(): void
    {
        $dataset = app(DatasetRegistry::class)->get('invoices');
        $sql = (new SnowflakeManifest)->generate($dataset, $this->partition('invoices', SyncMode::Upsert), $this->target());

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS analytics_billing.invoices (', $sql);
        $this->assertStringContainsString('total_minor NUMBER', $sql);
        $this->assertStringContainsString('COPY INTO analytics_billing.invoices_stage', $sql);
        $this->assertStringContainsString('FROM @BILLING_STAGE/billing/invoices/livemode=1/dt=2026-07-19/', $sql);
        $this->assertStringContainsString('FILE_FORMAT = (TYPE = JSON STRIP_OUTER_ARRAY = FALSE)', $sql);
        $this->assertStringContainsString('MERGE INTO analytics_billing.invoices AS t', $sql);
        $this->assertStringContainsString('ON t.id = s.id', $sql);
    }

    public function test_snowflake_append_is_a_plain_copy_no_merge(): void
    {
        $dataset = app(DatasetRegistry::class)->get('usage_events');
        $sql = (new SnowflakeManifest)->generate($dataset, $this->partition('usage_events', SyncMode::Append), $this->target());

        $this->assertStringContainsString('COPY INTO analytics_billing.usage_events', $sql);
        $this->assertStringNotContainsString('MERGE INTO', $sql);
    }

    public function test_bigquery_upsert_emits_bq_load_and_merge(): void
    {
        $dataset = app(DatasetRegistry::class)->get('invoices');
        $sql = (new BigQueryManifest)->generate($dataset, $this->partition('invoices', SyncMode::Upsert), $this->target());

        $this->assertStringContainsString('bq load', $sql);
        $this->assertStringContainsString('--source_format=NEWLINE_DELIMITED_JSON', $sql);
        $this->assertStringContainsString('analytics_billing.invoices_stage', $sql);
        $this->assertStringContainsString('"s3://analytics-bucket/billing/invoices/livemode=1/dt=2026-07-19/*.ndjson"', $sql);
        $this->assertStringContainsString('total_minor:INT64', $sql);
        $this->assertStringContainsString('MERGE INTO analytics_billing.invoices AS t', $sql);
    }

    public function test_bigquery_snapshot_replaces(): void
    {
        $dataset = app(DatasetRegistry::class)->get('revenue_snapshot');
        $sql = (new BigQueryManifest)->generate($dataset, $this->partition('revenue_snapshot', SyncMode::Snapshot), $this->target());

        $this->assertStringContainsString('--replace=true', $sql);
        $this->assertStringNotContainsString('MERGE INTO', $sql);
    }

    public function test_redshift_upsert_emits_copy_and_delete_insert(): void
    {
        $dataset = app(DatasetRegistry::class)->get('invoices');
        $sql = (new RedshiftManifest)->generate($dataset, $this->partition('invoices', SyncMode::Upsert), $this->target());

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS analytics_billing.invoices (', $sql);
        $this->assertStringContainsString('total_minor BIGINT', $sql);
        $this->assertStringContainsString("FROM 's3://analytics-bucket/billing/invoices/livemode=1/dt=2026-07-19/'", $sql);
        $this->assertStringContainsString("IAM_ROLE 'arn:aws:iam::123456789012:role/redshift-load'", $sql);
        $this->assertStringContainsString("FORMAT AS JSON 'auto ignorecase'", $sql);
        $this->assertStringContainsString('DELETE FROM analytics_billing.invoices USING analytics_billing.invoices_stage s', $sql);
        $this->assertStringContainsString('INSERT INTO analytics_billing.invoices', $sql);
    }

    public function test_redshift_append_csv_uses_csv_format(): void
    {
        $dataset = app(DatasetRegistry::class)->get('usage_events');
        $sql = (new RedshiftManifest)->generate($dataset, $this->partition('usage_events', SyncMode::Append, ExportFormat::Csv), $this->target());

        $this->assertStringContainsString('FORMAT AS CSV IGNOREHEADER 1', $sql);
        $this->assertStringNotContainsString('DELETE FROM', $sql);
    }
}
