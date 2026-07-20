---
title: Idempotency, dry-run & conflicts
description: How the source→app ledger makes imports idempotent, what the dry-run report shows, which conflicts are surfaced for resolution, and how the operator plan mapping routes source plans onto app plans.
weight: 30
---

# Idempotency, dry-run & conflicts

## The source→app ledger

Every imported record is recorded in `import_source_refs` as a stable mapping:

```
(source, source_type, source_id)  →  (app_type, app_id)      -- unique per plane
```

This is the idempotency key. Before creating anything, the importer looks the source record up:

- **found** → the record is **skipped** (already imported) — a re-run of the same export is a
  no-op, never a duplicate;
- **not found, but an app twin exists by natural key** (a product/plan/coupon key, a customer
  email) → the source record is **linked** to it (and the ref recorded), never duplicated;
- **not found** → a new app record is **created** through the real domain service, and the ref is
  recorded.

Because resolution is keyed on the ledger, an import is **re-runnable and resumable** — if a large
commit is interrupted, re-running it picks up exactly where it left off.

## Dry-run

Nothing is written until you commit. The dry-run resolves the **whole** export against the ledger
and reports, per entity, how many records would be **created / updated / skipped**, plus a full
list of **conflicts**. It runs the identical resolution the commit does, so the report is exact.

## Conflicts

A conflict is a problem only an operator can resolve — surfaced in the dry-run, never silently
guessed or written. The conflicted row is skipped; the rest still commit. The kinds:

- **Unmapped plan** — a subscription references a plan that is neither in the export, mapped, nor
  already imported. It is flagged, **never invented** — map it (below) and re-run.
- **Duplicate email** — a customer's email already belongs to an existing organization. An
  operator decides whether to link or create; the import never silently merges or forks.
- **Missing currency** — a plan/price or invoice carries no currency, so no priceable plan /
  bookable invoice can be made.
- **Unsupported interval** — a plan's cadence is not monthly or yearly. It is not coerced to
  monthly.
- **Mapping target missing** — an operator mapping points at an app plan that does not exist.

## Plan mapping

Catalog modelling differs between providers, so a source plan sometimes needs to point at an
**existing** app plan rather than being imported as a new one. The dry-run report includes a plan
mapping editor: for each source plan, leave it on **auto** (import it — matched by key, else
created) or **route it to an existing app plan**.

Resolution order for a subscription's plan is: operator mapping → a plan imported in this run → an
existing ledger ref. Resolvable by none of these ⇒ the unmapped-plan conflict above.

## Commit

The commit executes the plan through the domain services, records a per-run **log entry** for every
row (its outcome + the app record it resolved to — a browsable source→app mapping), and writes the
run to the [audit trail](../audit-compliance/_index.md). Small sets run inline; sets above the
queue threshold are dispatched to the queue, and the run log fills in as they process.
