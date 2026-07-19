---
title: The operator audit trail
description: The operator_audit_events table, the typed action catalog, the hash chain, the append-only DB guard, and the central recording seam that ensures every mutation is logged.
weight: 10
---

# The operator audit trail

Every operator mutation performed through the console is recorded as one immutable row in
`operator_audit_events`. The row captures **who** (`actor_sub`, `actor_name`, `actor_ip`),
**what** (`action`, `summary`, `metadata`), **to which resource** (`target_type`, `target_id`,
`organization_id`), on **which plane** (`livemode`), and **when** (`occurred_at`), plus the
chain fields (`sequence`, `prev_hash`, `hash`).

## The action catalog

Actions are a typed enum, `App\Billing\Audit\Enums\AuditAction`. Each case is a stable dotted
slug `<resource>.<verb-past>` that never changes once shipped, so a SIEM or export can pin on
it. A representative slice:

| Slug | When |
| --- | --- |
| `invoice.refunded` | An operator refunds an invoice (→ credit note) |
| `invoice.voided` / `invoice.marked_paid` | Void / manual settlement |
| `wallet.adjusted` | A promotional grant or corrective debit |
| `customer.suspended` / `customer.reactivated` | Access held / restored |
| `license.revoked` / `license.issued` | On-prem license lifecycle |
| `token.minted` / `token.revoked` | API token lifecycle |
| `coupon.created` / `plan.archived` / `exemption.verified` | Catalog & tax authoring |
| `dsar.exported` / `data.erased` | GDPR access export / erasure |
| `console.mutation` | Fallback for a mutation route with no explicit mapping |

The enum also owns the **route-name → action** map (`AuditAction::forRoute()`), so adding a
new mutation route is a one-line catalog entry, not a middleware edit.

## The hash chain

Each row chains to its predecessor:

```
hash = SHA-256( prev_hash · "\n" · canonical(payload) )
```

`canonical(payload)` is a deterministic JSON encoding (recursively key-sorted, slashes and
unicode unescaped) of the row's identity-defining fields — sequence, actor, action, target,
summary, metadata, plane and `prev_hash`. The genesis row chains from 64 zero hex chars. Because
each row's hash feeds the next row's input, editing any single row breaks that row **and every
row after it**.

`sequence` is a monotonic counter (genesis = 1). Appends are serialized with a `lockForUpdate`
on the chain tip inside a transaction; a `UNIQUE` constraint on `sequence` is the backstop for
the one unlockable case — the genesis insert on an empty table — where a lost race retries.

## The append-only guard

`operator_audit_events` is append-only at the **database** layer, not merely by convention.
The migration installs `BEFORE UPDATE` and `BEFORE DELETE` triggers per driver:

- **SQLite** — `RAISE(ABORT, …)`
- **PostgreSQL** — a `plpgsql` function that `RAISE EXCEPTION`
- **MySQL / MariaDB** — `SIGNAL SQLSTATE '45000'`

`INSERT` is never blocked; any `UPDATE`/`DELETE` is refused. (Dropping the table via
`migrate:fresh` still works — the guard blocks row mutation, not DDL.)

## The central recording seam — coverage guarantee

Two layers ensure a mutation can never silently skip the log:

1. **Explicit instrumentation** for the high-value actions (refund, wallet adjust, suspend,
   license revoke, token mint) records a rich event with a **before/after** diff, invoked from
   the mutation service or controller via the `RecordsAudit` contract. The wallet adjustment
   records *inside the same transaction* as the money movement, so the two commit together.
2. **A catch-all middleware** (`RecordsOperatorAudit`) runs on every console route. After a
   successful write request that recorded nothing itself, it appends a fallback event mapped to
   the route's action (or `console.mutation` when unmapped). Coordination is a per-request
   tally: an explicitly-instrumented request logs exactly one (rich) event and the middleware
   stays silent; an un-instrumented one still gets covered.

A request is recorded only when it **mutated**: a write verb, an auditable route (not a
read-only `*.preview`, the test-mode toggle, or auth), a non-error response, and no flashed
`error` (a guard that refused the action redirects back with one).

## Secrets are never logged

The recorder logs the **fact and a reference**, never a secret value. A token mint records the
token id, scope and mode — never the plaintext. A license revoke records the license id — never
the signed key. A certificate action records the fact — never the document bytes. The audit
trail is PII-minimized by construction, which matters because it is itself immutable: an erasure
can never reach back to redact a value the trail should never have stored.

## System vs operator actor

An interactive request is attributed to the signed-in operator's `sub`. A scheduled command or
queue job — which has no operator session — is attributed to the `system` sentinel, so an
unattended action is recorded honestly rather than as a fabricated person.
