---
title: Audit trail & GDPR tooling
description: The tamper-evident, append-only operator audit trail (hash-chained, DB-guarded), plus the GDPR/DSAR access export and the right-to-be-forgotten erasure policy — what is redacted vs legally retained.
weight: 58
---

# Audit trail & GDPR tooling

Enterprise and regulated buyers need two things a billing operator cannot hand-wave:
an **exportable, tamper-evident record of every operator action**, and **data-subject
tooling** (access export + erasure) that respects statutory financial-record retention.
Cbox Billing ships both as first-party capabilities.

This is distinct from the per-customer **activity view** (`CustomerAuditLog`), which merely
*derives* a timeline from existing records. The trail documented here is the immutable,
append-only record of operator *mutations* — who did what, to which resource, with the
before/after where meaningful.

## What's here

- **[The operator audit trail](audit-trail.md)** — the event catalog, the hash chain, the
  DB-level append-only guard, and the single recording seam that guarantees coverage.
- **[Chain integrity & `audit:verify`](chain-integrity.md)** — how verification walks the
  chain, what a break looks like, and the honest scope: **tamper-evident, not tamper-proof**.
- **[DSAR access export](dsar-export.md)** — the data-subject access bundle: what it contains
  and how it reuses the data-export system.
- **[Erasure: redact vs retain](erasure.md)** — the right-to-be-forgotten policy, stating
  exactly what PII is redacted and which financial records are legally retained (de-identified).

## Honesty stance

We never claim more than the mechanism delivers. The chain is **tamper-evident, not
tamper-proof**: it reliably surfaces partial edits, DB fiddling and corruption, but an actor
with full DB *and* application access can recompute a self-consistent chain. And erasure is
**pseudonymization, not deletion**, wherever the law requires the financial record to survive —
the UI and this documentation say so plainly rather than promising a "fully erased" subject.
