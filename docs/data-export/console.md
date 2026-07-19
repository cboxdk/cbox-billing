---
title: The console
description: The Data → Exports and Data → Warehouse console areas — downloading exports, configuring sinks, running a sync now, and inspecting load manifests — plus the warehouse:sync command.
weight: 60
---

# The console

The **Data** area holds two pages, wired into the nav and the ⌘K palette.

## Data → Exports

Gated `analytics:read`. Pick a dataset, a format (CSV / NDJSON) and an optional inclusive
date range, and download the current plane's data. The download streams straight from the
database — the whole dataset is never assembled in memory. A dataset catalog lists every
dataset with its load mode, column count and date axis, and one-click CSV/NDJSON links. The
recent warehouse sync-run log is shown below.

The download route:

```
GET /exports/download?dataset={key}&format={csv|ndjson}&from=YYYY-MM-DD&to=YYYY-MM-DD
```

`from`/`to` are optional and inclusive; the plane is the console's current mode (live or
test/sandbox toggle).

## Data → Warehouse

Gated `settings:read` (view) / `settings:manage` (mutate). Configure a sink:

- **disk / prefix** — where partitions are staged (`s3` + `billing/export`).
- **format** — NDJSON (recommended for warehouses) or CSV.
- **plane** — live or test; a sink exports exactly one.
- **warehouse** — the dialect its load manifests are phrased for (Snowflake / BigQuery /
  Redshift), or "staged files only".
- **datasets** — the datasets this sink delivers.
- **schedule** — an optional cron note.
- **external base / schema / stage / credential** — the load-side coordinates the manifests
  reference (see [warehouse load manifests](warehouse-sinks.md)).

Each sink offers **Run now** (stage all its datasets immediately), **Enable/Disable**,
**Remove**, and a per-dataset link to its generated **load manifest**. The per-sink run log
shows recent deliveries with row/byte counts and the advancing cursor.

## The `warehouse:sync` command

```bash
php artisan warehouse:sync                          # every enabled sink, every dataset
php artisan warehouse:sync --sink=analytics-s3      # one sink
php artisan warehouse:sync --sink=analytics-s3 --dataset=invoices
```

Scheduled hourly in `routes/console.php`. Idempotent and cursor-driven — see
[incremental sync](incremental-sync.md).

## Configuration

`config/billing.php → export`:

- `chunk_size` (`CBOX_BILLING_EXPORT_CHUNK_SIZE`, default `500`) — the streaming chunk size;
  every export reads the database in chunks of this many rows and never materialises the whole
  dataset.
- `default_disk` (`CBOX_BILLING_EXPORT_DISK`, default `s3`) — the disk a new sink defaults to.
