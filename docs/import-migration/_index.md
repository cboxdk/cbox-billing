---
title: Import & migration
description: Bring a seller's catalog, customers, subscriptions and historical invoices over from Stripe, Chargebee or Recurly by uploading their export files — an idempotent, dry-run-first, audit-logged migration that preserves historical dates so MRR history and cohorts stay correct.
weight: 56
---

# Import & migration

Switching cost is the biggest adoption blocker for a billing system. The import tooling removes
it: a seller uploads their existing provider's data export and Cbox Billing recreates their
catalog, customers, subscriptions, coupons and historical invoices — through the **real domain
services** the console itself writes through, not raw inserts.

The design goals, and where each is documented:

- **Credential-free, file-based.** The supported path is uploading the provider's own export
  file(s); each adapter maps that provider's field names + unit convention into a normalized
  model. See [Supported sources](sources.md) and [Field & unit mapping](field-mapping.md).
- **Dry-run first.** Every import is planned before it is committed — a per-entity report of what
  would be created / updated / skipped, plus conflicts (an unmapped plan, a duplicate email, an
  unsupported currency/interval) surfaced for resolution *before* any write. See
  [Idempotency, dry-run & conflicts](idempotency-dry-run.md).
- **Idempotent.** Re-running the same export changes nothing — records are matched on a durable
  source→app ledger. Same page.
- **Historically faithful.** Signup dates, subscription period anchors, MRR-movement timing and
  invoice dates are preserved, so cohorts and MRR history line up after the cut-over. See
  [Historical-date preservation](historical-dates.md).
- **Honest about the live-API boundary.** A live-credentialed pull is a documented seam, not a
  shipped-with-faked-auth client. See [The API-pull seam](api-pull-seam.md).

## The flow

1. **Upload** — pick a source (Stripe / Chargebee / Recurly) and upload its export file(s), or
   paste the combined JSON. Nothing is written.
2. **Dry-run** — review the plan: counts per entity, conflicts to resolve, and the proposed plan
   mapping to adjust (route a source plan onto an existing app plan).
3. **Commit** — execute. Small sets run inline; large sets are queued. A per-run log records the
   source→app id mapping for every record, and the run is written to the audit trail.

The Import area lives under **Data → Import** in the console and is gated `settings:manage`. Import
into **test mode** first to validate — a run is scoped to one plane and test data never leaks into
live.
