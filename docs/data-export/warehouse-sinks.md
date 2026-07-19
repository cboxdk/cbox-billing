---
title: Warehouse load manifests
description: The exact per-warehouse COPY / bq load / DDL Cbox Billing emits for staged partitions — Snowflake, BigQuery and Redshift — with copy-paste examples, plus the honest direct-push seam.
weight: 40
---

# Warehouse load manifests

Snowflake, BigQuery and Redshift all bulk-load from **staged files in object storage**, not
row-by-row inserts. Cbox Billing writes those staged files (see
[staged file layout](staged-files.md)) and, for each, emits the **exact load statement** the
warehouse runs — a real, runnable artifact, with no bundled vendor SDK. The statement is
written next to the data as `load-{warehouse}.sql`, and is previewable in the console
(Data → Warehouse → a dataset).

The load grammar follows the dataset's [sync mode](incremental-sync.md):

- **`append`** (e.g. the usage-event stream) → a plain `COPY` / `bq load` that adds rows.
- **`upsert`** (dimensions like invoices, customers) → load into a staging table, then a
  `MERGE` (Snowflake/BigQuery) or `DELETE … USING` + `INSERT … SELECT` (Redshift) on the
  natural key, so a changed row is replaced, not duplicated.
- **`snapshot`** (the revenue snapshot) → `TRUNCATE`/`--replace=true`, a full refresh.

Column types map to each warehouse's physical types (`integer`→`NUMBER`/`INT64`/`BIGINT`,
`timestamp`→`TIMESTAMP_NTZ`/`TIMESTAMP`/`TIMESTAMP`, `json`→`VARIANT`/`JSON`/`SUPER`, …).

Bracketed placeholders (`<snowflake-external-stage>`, `<redshift-iam-role-arn>`,
`<aws-region>`) are values Cbox Billing does **not** invent — you fill them in from your
load-side configuration (or set them on the sink: external base, schema, stage, credential).

## Snowflake — `COPY INTO` (append example)

```sql
-- Snowflake load manifest — dataset `usage_events` (append load)
CREATE TABLE IF NOT EXISTS analytics_billing.usage_events (
 event_id VARCHAR,
 org VARCHAR,
 meter VARCHAR,
 service VARCHAR,
 value NUMBER,
 unique_key VARCHAR,
 weight NUMBER,
 occurred_at TIMESTAMP_NTZ,
 occurred_at_ms NUMBER
);
COPY INTO analytics_billing.usage_events
FROM @BILLING_STAGE/billing/export/usage_events/livemode=1/dt=2026-07-19/
 FILE_FORMAT = (TYPE = JSON STRIP_OUTER_ARRAY = FALSE)
 MATCH_BY_COLUMN_NAME = CASE_INSENSITIVE
 ON_ERROR = ABORT_STATEMENT;
```

An `upsert` dataset additionally emits a `CREATE TEMPORARY TABLE … LIKE` staging table and a
`MERGE INTO … WHEN MATCHED THEN UPDATE … WHEN NOT MATCHED THEN INSERT` on the merge key.
`@BILLING_STAGE` is your external stage created over the sink's object-store location.

## BigQuery — `bq load` (append example)

```bash
# BigQuery load manifest — dataset `usage_events` (append load)
bq load \
 --source_format=NEWLINE_DELIMITED_JSON \
 --replace=false \
 analytics_billing.usage_events \
 "gs://acme-datalake/billing/export/usage_events/livemode=1/dt=2026-07-19/*.ndjson" \
 event_id:STRING,org:STRING,meter:STRING,service:STRING,value:INT64,unique_key:STRING,weight:INT64,occurred_at:TIMESTAMP,occurred_at_ms:INT64
```

An `upsert` dataset loads into a `_stage` table (`--replace=true`) then runs a
`bq query … 'MERGE INTO … '`; a `snapshot` uses `--replace=true` straight onto the target.

## Redshift — `COPY … IAM_ROLE` (upsert example)

```sql
-- Redshift load manifest — dataset `customers` (upsert load)
CREATE TABLE IF NOT EXISTS analytics_billing.customers ( id VARCHAR(65535), /* … */ updated_at TIMESTAMP );

BEGIN TRANSACTION;
CREATE TEMPORARY TABLE analytics_billing.customers_stage (LIKE analytics_billing.customers);
COPY analytics_billing.customers_stage
FROM 's3://acme-datalake/billing/export/customers/livemode=1/dt=2026-07-19/'
IAM_ROLE 'arn:aws:iam::123456789012:role/redshift-load'
 FORMAT AS JSON 'auto ignorecase'
 TIMEFORMAT 'auto'
 REGION '<aws-region>';
DELETE FROM analytics_billing.customers USING analytics_billing.customers_stage s
 WHERE analytics_billing.customers.id = s.id;
INSERT INTO analytics_billing.customers ( id, /* … */ updated_at )
 SELECT id, /* … */ updated_at FROM analytics_billing.customers_stage;
END TRANSACTION;
```

CSV partitions use `FORMAT AS CSV IGNOREHEADER 1` instead of the JSON clause.

## The `external base` and the staged prefix

The manifest's staged location is `{external_base}/{partition_path}`. Set the sink's
**external base** to the object-store/stage **root** (`s3://acme-datalake`,
`gs://acme-datalake`, or a Snowflake stage) — the sink's prefix (`billing/export`) is already
part of the partition path, so it must **not** be repeated in the external base.

## Direct-push seam (honest boundary) {#direct-push-seam}

The staged-file + load-manifest path above is the **real, always-available** delivery. It is
fully functional with only object-store credentials.

Pushing the staged partition into the warehouse over a **live API** (a JDBC/HTTP loader, a
Snowpipe REST call, a `bq` invocation) is a separate, optional capability. It lives behind the
`App\Billing\Export\Contracts\WarehousePush` seam, whose shipped default —
`NullWarehousePush` — performs no push and fabricates no client or auth. A deployment that
wants live push binds its own implementation:

```php
$this->app->bind(
    \App\Billing\Export\Contracts\WarehousePush::class,
    \App\Billing\Export\Push\MySnowpipePush::class,
);
```

Until then, the load side is the operator's (or a scheduled loader's) responsibility — run the
generated `load-{warehouse}.sql`, or point a Snowpipe / external table / scheduled `bq load`
at the staged prefix. This is deliberate: **the app never claims a warehouse integration it
does not actually have.**
