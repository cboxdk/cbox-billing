---
title: Erasure — redact vs retain
description: The right-to-be-forgotten policy — exactly which PII is redacted to tombstones or deleted, which financial records are legally retained (de-identified), and why erasure is pseudonymization, not deletion.
weight: 40
---

# Erasure — redact vs retain

The right to erasure collides with statutory financial-record retention: invoices, credit notes
and the ledger **must** be kept for legal periods (commonly 5–10 years). Cbox Billing resolves
that tension honestly — erasure **pseudonymizes PII in place** and **retains the financial
records in a de-identified form**. It never hard-deletes a financial document, and it never
claims a subject is "fully erased" while records are retained.

Erasure is performed by `RedactsSubjectData` (the `SubjectErasureService`), triggered from the
GDPR/DSAR console. It runs in a transaction and records a `data.erased` audit event.

## Redacted (removed / tombstoned)

| Data | Action |
| --- | --- |
| Organization `name` | Replaced with a deterministic tombstone `[erased organization <hash8>]` |
| Organization `billing_email` | Set to `null` |
| Organization `tax_id` (+ `tax_id_validated`) | Set to `null` / `false` |
| Organization `billing_subdivision` | Set to `null` |
| Tax-exemption **certificate documents** | Deleted from the private disk; path/name/mime/size and notes cleared |
| Gateway-customer mappings | Detached (local pointer into the card vault removed) |

The organization row is then stamped `erased_at` / `erased_by_sub`.

## Retained (legally required — de-identified)

| Data | Why kept |
| --- | --- |
| Invoices (+ lines) & totals | Statutory tax/accounting retention |
| Credit notes & the ledger | Statutory retention; ledger immutability |
| Wallet adjustments, settled payments | Financial audit trail |
| The tax-exemption **certificate row** | Tax record — kept, but de-identified (document deleted) |
| Operator **audit events** | Security/compliance record; PII-minimized by construction |

These records reference the organization only by its **opaque id** (a pseudonymous handle) and
carry no name or email of their own, so redacting the organization row removes the PII while the
money trail stays intact and auditable. A DSAR **re-export after erasure** shows tombstones in
`customers.ndjson`, not the original PII, while `invoices.ndjson` still carries the retained
amounts.

## Why the audit trail is not purged

The audit events are immutable (append-only) and are themselves a legitimate security/compliance
processing record, so erasure does **not** delete them. This is safe because the recorder never
stores PII values — only ids, references, field names and counts — so a retained event cannot
resurrect what erasure removed. The `data.erased` event records *which* fields were redacted and
*what* was retained (counts), never the old values.

## What erasure does NOT do

- It does not delete the customer at the payment gateway. The local mapping is detached; the
  operator deletes the gateway customer out-of-band (the gateway owns that vault).
- It does not remove the subject from immutable financial or audit records — by design and by law.
