---
title: Staged file layout
description: How a warehouse sink stages dataset partitions as Hive-style partitioned files in object storage, the JSON delivery manifest written alongside, and the disk it uses.
weight: 30
---

# Staged file layout

A warehouse sink stages each dataset as **partitioned files** on a Laravel filesystem disk —
the way Snowflake, BigQuery and Redshift ingest at scale. With an `s3` disk configured
(`config/filesystems.php`), this is fully functional; no warehouse SDK is involved.

## Partition path

```
{prefix}/{dataset}/livemode={0|1}/dt={YYYY-MM-DD}/part-{cursorLo}-{cursorHi}.{ext}
```

- **`{prefix}`** — the sink's configured object-store prefix (e.g. `billing/export`).
- **`{dataset}`** — the dataset key (`invoices`, `usage_events`, …).
- **`livemode={0|1}`** — the plane. A sink exports exactly one plane; test and live data are
  never mixed in a partition.
- **`dt={YYYY-MM-DD}`** — the sync-run date (Hive-style date partition an external table
  understands). The event's own instant is inside every row for finer re-partitioning.
- **`part-{cursorLo}-{cursorHi}`** — the incremental cursor window the file covers. Re-running
  the same window overwrites the same file, so a re-run is idempotent. A `snapshot` dataset
  writes a single `snapshot.{ext}` per date instead (full replace).

Example:

```
billing/export/usage_events/livemode=1/dt=2026-07-19/part-1-500.ndjson
billing/export/invoices/livemode=1/dt=2026-07-19/part-1-42.ndjson
```

## Delivery manifest

Alongside each staged data file, the sink writes a JSON **delivery manifest**
(`…​.manifest.json`) describing the file — the dataset, plane, sync mode, row/byte counts,
the cursor window, the natural (merge) key, and the full column schema:

```json
{
  "dataset": "invoices",
  "sink": "analytics-s3",
  "format": "ndjson",
  "sync_mode": "upsert",
  "livemode": true,
  "partition_date": "2026-07-19",
  "file": "part-1-42.ndjson",
  "rows": 42,
  "bytes": 18140,
  "cursor_from": "1",
  "cursor_to": "42",
  "merge_keys": ["id"],
  "columns": [{"name": "id", "type": "integer"}, {"name": "number", "type": "string"}]
}
```

## Load manifest

When the sink targets a warehouse dialect, the exact load statement is written next to the
data as `load-{warehouse}.sql` — see [warehouse load manifests](warehouse-sinks.md).

## Choosing the disk

Any disk in `config/filesystems.php` works. Use `s3` (or an S3-compatible endpoint) for a
real deployment; a local disk works for testing or an on-box lake. The default disk a new
sink suggests is `config('billing.export.default_disk')` (`s3`).
