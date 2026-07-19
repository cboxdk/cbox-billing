---
title: DSAR access export
description: The data-subject access bundle — what a subject's export contains, how it reuses the data-export encoders, and how the export is itself audit-logged.
weight: 30
---

# DSAR access export

A data-subject access request (DSAR) is answered with a **bundle**: one `.tar.gz` archive
containing a `manifest.json` plus one newline-delimited-JSON (NDJSON) file per dataset the
subject appears in.

## What the bundle contains

Assembled for one organization on one plane, drawing from the subject-scopable datasets:

- `customers.ndjson` — the organization profile
- `subscriptions.ndjson`
- `invoices.ndjson` and `invoice_lines.ndjson`
- `credit_notes.ndjson`
- `payments.ndjson`
- `mrr_movements.ndjson`
- `coupon_redemptions.ndjson`
- `seat_assignments.ndjson`
- `licenses.ndjson`
- `usage_events.ndjson`
- `dunning.ndjson`
- `audit_events.ndjson` — the operator-action trail *about* this subject, with hash-chain
  columns so it can be verified independently

Only datasets that yield at least one row for the subject are included. The `manifest.json`
records the subject, the plane, a generation timestamp, the per-dataset row counts, and the
redact-vs-retain note.

## Reuse of the data-export system

The bundle is built by **reusing** the Wave-1 data-export encoders, not by re-implementing
serialization. Each dataset is pumped through the same `DataExporter` and NDJSON `RowEncoder`
the console/warehouse exports use, scoped to the subject via `ExportQuery::forOrganization()`.

Subject scoping is **deny-by-default**: a dataset that cannot be scoped to a single subject
contributes **nothing** rather than the whole plane, so one subject's bundle can never leak
another's rows. Child datasets without their own org column (invoice lines) scope through their
parent (the subject's invoices).

## The export is itself audited

Assembling a DSAR bundle is a processing action, so it records a `dsar.exported` audit event
(subject, plane, per-dataset counts). Downloading the archive streams it and deletes the
temporary file after send.
