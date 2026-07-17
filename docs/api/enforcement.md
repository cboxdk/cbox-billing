---
title: Enforcement API
description: The metered hot path — leases, reserve, commit, usage ingest, and entitlement checks — with request/response shapes and the three-way reserve outcome.
weight: 52
---

# Enforcement API

The enforcement API is the metered hot path the `cboxdk/laravel-billing-client` SDK
consumes on every operation. All routes are under `/api/v1`, bearer-token
authenticated, per-org scoped, and throttled at the higher `cbox-enforcement` tier
(600/min by default).

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/leases` | Lease a slice of an org's remaining allowance for a meter. |
| `POST` | `/api/v1/usage` | Ingest cumulative usage for a meter. |
| `POST` | `/api/v1/reserve` | Reserve meter buckets, all-or-nothing (three-way outcome). |
| `POST` | `/api/v1/commit` | Settle a reservation to actual usage. |
| `GET` | `/api/v1/entitlements/{org}` | The org's resolved per-meter entitlements. |

## `POST /leases`

Lease a slice of an org's remaining allowance for a meter — billing's side of the
pessimistic lease the SDK refills from.

```json
// request
{ "org": "org_123", "meter": "api.requests", "size": 100 }
```
```json
// response
{ "lease_id": "lease_…", "granted": 100, "expires_at": "2026-07-17T12:00:00+00:00" }
```

`granted` may be fewer than `size` when the includable allowance is nearly spent, or
0 when exhausted. TTL is `CBOX_BILLING_LEASE_TTL` (300s).

## `POST /reserve`

Reserve one or more meter buckets in one all-or-nothing call.

```json
// request
{ "org": "org_123", "meters": [ { "meter": "api.requests", "estimate": 1 } ] }
```

The response is the engine's **three-way outcome**:

```json
// allowed
{ "outcome": "allowed", "reservation_id": "…" }

// denied (semantic refusal — fail-closed)
{ "outcome": "denied", "reason": "quota_exceeded",
  "upgrade": { "required_plan": "team", "checkout_url": "https://…" } }

// indeterminate (a dependency was down; infra policy decided)
{ "outcome": "indeterminate", "reason": "dependency unavailable" }
```

- **allowed** — the held set is persisted for the matching `/commit`.
- **denied** — an unknown/disabled meter or an exhausted allowance. When a reachable
  plan grants the blocking meter, an `upgrade` object carries the minimum plan and a
  pre-built checkout deep-link (omitted when there is no path). See
  [Metering & enforcement](../concepts/metering-and-enforcement.md).
- **indeterminate** — a dependency was unavailable; the deployment's
  `CBOX_BILLING_INFRA_FAILURE` policy decided admit/refuse, and it is signalled.

## `POST /commit`

Settle a reservation to the actual usage per meter.

```json
// request
{ "reservation_id": "…", "actuals": [ { "meter": "api.requests", "actual": 1 } ] }
```
```json
// response
{ "ok": true }
```

`commit` returns unused allowance/lease and appends one durable usage event per
bucket with non-zero usage, then drops the reservation. A missing/expired
reservation is **404**; an actual exceeding its reserved estimate is a **422** from
the engine's validation. Always commit (or the SDK releases) on the work's outcome.

## `POST /usage`

Ingest cumulative usage for a meter directly (the metering-only path, without a
reserve/commit round-trip). The event log dedups within `CBOX_BILLING_DEDUP_WINDOW_DAYS`;
late duplicates are caught by reconciliation.

## `GET /entitlements/{org}`

Returns the org's resolved per-meter policy (`enabled`, `allowance`, `weight`,
`overage`). Disabled meters that have a reachable upgrade path are enriched with an
`upgrade` object (the same enforce→upgrade bridge as `reserve`).

## Related documentation

- [Concepts → Metering & enforcement](../concepts/metering-and-enforcement.md)
- [Cookbook → Meter usage on the hot path](../cookbook/meter-usage.md)
- [Authentication](authentication.md)
