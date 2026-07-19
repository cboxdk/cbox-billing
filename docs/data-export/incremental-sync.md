---
title: Incremental sync
description: The per-(sink, dataset) watermark that makes a scheduled warehouse sync ship only new or changed rows, the three sync modes, and the run log.
weight: 50
---

# Incremental sync

The scheduled `warehouse:sync` is **incremental**: each dataset ships only the rows added or
changed since its last delivery, tracked by a per-`(sink, dataset)` **watermark**.

## Cursor & watermark

Every dataset declares a **cursor** — a monotonic column an incremental run orders and filters
by. This is deliberately distinct from the business date axis a user picks a range against:

- **Id cursor** (`CursorKind::Id`) — a strictly-monotonic auto-increment key. Used by the
  append-only event log and most surrogate-keyed tables. The next run filters
  `where id > {watermark}` — clean, no duplicates.
- **Timestamp cursor** (`CursorKind::Timestamp`) — an `updated_at` (or mint instant) for a
  string-keyed dimension (customers, licenses). The next run filters `where {col} > {watermark}`.

After a run stages a partition, the watermark advances to the highest cursor value delivered.
The advance and the run-log row commit in one transaction, so a crash never advances past
un-logged data. The watermark lives in `warehouse_sync_cursors`.

## Sync modes

| Mode | Datasets | Behaviour |
|---|---|---|
| `append` | usage events, MRR movements, coupon redemptions, licenses | Immutable stream. Ships rows past the watermark into a new partition; existing partitions are never rewritten; the load is a plain `COPY`/`bq load`. |
| `upsert` | invoices, invoice lines, subscriptions, customers, credit notes, payments, coupons, dunning, seats | Mutable dimension. Ships everything touched since the watermark; the load `MERGE`s on the natural key, so a changed row is replaced, not duplicated. |
| `snapshot` | revenue snapshot | Full recompute. No watermark — each run stages the whole current set and the load truncates-and-replaces. |

## Idempotency

- A run with no new data since the watermark stages **nothing** (an `empty` run) — no empty
  files accumulate.
- Re-running the same window writes to the same cursor-named partition path, overwriting it —
  so a re-run never double-delivers.
- An `upsert` load is idempotent by construction (the `MERGE`/`DELETE+INSERT` keys on the
  natural id).

## The run log

Every delivery is recorded in `warehouse_sync_runs`: the dataset, status
(`success`/`empty`/`failed`), row and byte counts, the cursor window, the staged path, the load
manifest path, timings, and any error. It surfaces in the console (Data → Exports, and per
sink under Data → Warehouse). A dataset failure is isolated — it is logged and the sync moves
on to the next dataset rather than aborting the whole pass.

## Cadence

`warehouse:sync` is scheduled hourly (`routes/console.php`) with `withoutOverlapping()`.
Because it is cursor-driven and idempotent, running it more often only tightens freshness —
it never duplicates a delivered row. Run one sink or one dataset on demand:

```bash
php artisan warehouse:sync --sink=analytics-s3
php artisan warehouse:sync --sink=analytics-s3 --dataset=usage_events
```
