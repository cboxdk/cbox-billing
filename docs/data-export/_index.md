---
title: Data export & warehouse sinks
description: Stream any billing dataset to CSV or NDJSON, and stage partitioned files plus copy-paste load manifests to Snowflake, BigQuery and Redshift — the real warehouse ingestion pattern, no bundled SDK.
weight: 55
---

# Data export & warehouse sinks

Cbox Billing exposes every billing dataset — invoices, subscriptions, customers, the
recurring-revenue bridge, credit notes, payments, coupons, dunning, seats, licenses and
the **raw usage-event stream** — as a streamed, typed export, and stages those datasets
to object storage the way Snowflake, BigQuery and Redshift actually ingest at scale.

There are two delivery surfaces, both real:

1. **On-demand exports** — pick a dataset, a format (CSV or NDJSON) and an optional date
   range in the console (Data → Exports), or hit the streamed download route, and get the
   current plane's data. Every export streams straight from the database with a chunked
   cursor, so memory stays bounded no matter how large the table.
2. **Warehouse sinks** — a configured sink stages each dataset as **partitioned NDJSON/CSV
   files** in an object-store disk (`s3` or any Laravel filesystem disk), writes a JSON
   delivery manifest alongside, and emits the exact per-warehouse **load manifest**
   (`COPY INTO` / `bq load` / DDL) an operator or a scheduled loader runs. A scheduled
   `warehouse:sync` ships only rows changed since each dataset's watermark.

## The honest boundary: staged files (real) vs direct push (seam)

Warehouses load at scale from **staged files in object storage**, not row-by-row inserts.
That staged-file + load-manifest path is fully built and fully functional with nothing more
than object-store credentials — there is **no bundled warehouse SDK and no fabricated
client**. Pushing the staged files into the warehouse over a live API is left to an
explicit, documented seam ([`WarehousePush`](warehouse-sinks.md#direct-push-seam)) whose
shipped default is a no-op. This keeps the app honest: everything it claims to do, it does.

## In this section

- **[Dataset schemas](dataset-schemas.md)** — the stable, typed schema of every dataset.
- **[Formats: CSV & NDJSON](formats.md)** — the two encodings and how types are rendered.
- **[Staged file layout](staged-files.md)** — the partition paths, manifests and the disk.
- **[Warehouse load manifests](warehouse-sinks.md)** — the per-warehouse `COPY`/`bq load`/DDL, with copy-paste examples, and the direct-push seam.
- **[Incremental sync](incremental-sync.md)** — the per-(sink, dataset) watermark and the sync mode of each dataset.
- **[The console](console.md)** — the Exports + Warehouse areas and the `warehouse:sync` command.
