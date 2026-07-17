---
title: Metering & enforcement
description: The reserve/commit hot path, allowance leasing, the three-way enforcement outcome (fail-open on infra, fail-closed on semantics), convergent reconciliation, and the enforce-to-upgrade bridge.
weight: 43
---

# Metering & enforcement

Metering answers two different questions with two different mechanisms:

- **May this request proceed right now?** — enforcement, sub-millisecond, against a
  leased allowance slice.
- **What actually happened?** — the immutable event log, reconciled into the ledger.

Cbox Billing is the **enforcement authority** here (unlike an edge SDK), so it binds
the central budget, the durable event log, and the lease-backed enforcer.

## The hot path: reserve → commit

The enforcement API is what a metered service calls on every operation:

1. **`reserve`** holds an estimate for one or more meter buckets, all-or-nothing,
   against the org's leased allowance. It hard-blocks when the allowance is
   exhausted.
2. Do the work.
3. **`commit`** settles the reservation to the actual usage per meter (releasing any
   difference) and appends one durable usage event per non-zero bucket.

On the error path, release the reservation. A missing/expired reservation is a 404;
an actual exceeding its reserved estimate is a 422. See
[API → Enforcement](../api/enforcement.md) for exact shapes.

## Allowance leasing

Enforcement is local per node against a **leased slice** of the org's allowance — no
shared or co-located Redis required. A node leases up to `lease.default_size` (100)
units for a meter via `POST /api/v1/leases`; the central budget grants up to that
(fewer when the includable allowance is nearly spent, 0 when exhausted) and holds
them until returned or expired (`CBOX_BILLING_LEASE_TTL`, 300s). The SDK refills
when depleted. A small, bounded, backfillable drift is accepted by design.

## The three-way outcome

Enforcement never collapses "denied" and "couldn't decide" into one. The
`reserve` response maps the engine's outcome directly:

| Outcome | Meaning | Policy |
| --- | --- | --- |
| **allowed** | The buckets fit; a `reservation_id` is returned. | — |
| **denied** | A semantic refusal — unknown/disabled meter, exhausted allowance. | Always **fail-closed**. |
| **indeterminate** | A dependency was down (store/cache/lock/transport). | The deployment's `infra_failure` policy decided admit or refuse, and it is signalled. |

`CBOX_BILLING_INFRA_FAILURE` controls the infra path: **`allow`** (default) fails
open so a blip does not throttle paid traffic (the ledger reconciles the truth), or
**`deny`** fails closed for strict tenants. Semantics always fail closed. This is
[ADR-0004](https://github.com/cboxdk/laravel-billing/tree/main/adr).

## Aggregations and multi-dimensional metering

Meters are independent dimensions with isolated allowances (the demo seeds
`api.requests`, `seats`, `storage.gb`, `events.ingested`). A single `reserve` can
hold several buckets at once, all-or-nothing. Usage aggregation and the derived
hot-path balance are the engine's ([ADR-0005](https://github.com/cboxdk/laravel-billing/tree/main/adr),
[ADR-0008](https://github.com/cboxdk/laravel-billing/tree/main/adr)).

## Convergent reconciliation

The fast counter and the durable ledger are trued up by the reconciler, which posts
a **cumulative delta against a per-entity checkpoint** — never replaying events
([ADR-0003](https://github.com/cboxdk/laravel-billing/tree/main/adr)). The app runs
it on a short cadence: `billing:reconcile-active` every 15 minutes. Knobs
(`config/billing.php` → `reconciliation`):

- `ingest_lag_seconds` (60) — only reconcile up to `now − lag`, so in-flight events
  are not counted early.
- `window_days` (32) — usage older than the window is bucketed `aged_out`, never
  silently dropped.
- `currency` (EUR) — the allowance denomination deltas are carried in.
- `dedup_window_days` (32) — re-delivered events counted exactly once; late
  duplicates are caught by reconciliation.

## The enforce → upgrade bridge

A semantic **denial can carry the path to unlock**. The `UpgradeGate` resolves the
minimum reachable plan that grants the blocked meter and mints a pre-built hosted
checkout deep-link to buy it, attaching `{required_plan, checkout_url}` to the
denial (and to disabled meters on the entitlements response). Deny-by-default: no
reachable plan ⇒ no offer, never a fabricated target. The return URL is
`CBOX_BILLING_UPGRADE_RETURN_URL`. See [Org-level entitlements](../identity/entitlements.md).

## Related documentation

- [API → Enforcement](../api/enforcement.md)
- [Cookbook → Meter usage on the hot path](../cookbook/meter-usage.md)
- [Wallets & credits](wallets-and-credits.md)
- Engine metering: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
