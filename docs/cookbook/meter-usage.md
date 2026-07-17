---
title: Meter usage on the hot path
description: The reserve → work → commit pattern against a meter, handling the three-way outcome, and the direct usage-ingest path.
weight: 83
---

# Meter usage on the hot path

Guard a metered operation against an org's allowance, then record what actually
happened. This is the [enforcement API](../api/enforcement.md) in practice.

## Reserve → work → commit

```bash
# 1. Reserve an estimate (all-or-nothing across buckets).
curl -s -X POST http://localhost:8000/api/v1/reserve \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{ "org": "org_123", "meters": [ { "meter": "api.requests", "estimate": 1 } ] }'
# → { "outcome": "allowed", "reservation_id": "resv_..." }

# 2. ...do the work...

# 3. Commit the actual usage (appends the durable event).
curl -s -X POST http://localhost:8000/api/v1/commit \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{ "reservation_id": "resv_...", "actuals": [ { "meter": "api.requests", "actual": 1 } ] }'
# → { "ok": true }
```

If the work fails, the SDK releases the reservation instead of committing.

## Handle the three-way outcome

```
allowed        → proceed; keep the reservation_id for commit
denied         → refuse (HTTP 429); if `upgrade` is present, surface the checkout_url
indeterminate  → a dependency was down; the infra policy already decided admit/refuse
```

A `denied` outcome may carry an `upgrade` object — the minimum reachable plan that
grants the blocked meter and a pre-built hosted-checkout deep-link:

```json
{ "outcome": "denied", "reason": "quota_exceeded",
  "upgrade": { "required_plan": "business", "checkout_url": "https://…" } }
```

Send the customer to `checkout_url` to unlock. See
[Metering & enforcement](../concepts/metering-and-enforcement.md).

## In production: lease locally

A metered service running the `cboxdk/laravel-billing-client` SDK leases a slice of
the allowance (`POST /api/v1/leases`) and enforces locally per node, refilling when
depleted — so `reserve` is a local check, not a round-trip per request. Tune the
slice with `CBOX_BILLING_LEASE_SIZE`.

## Direct usage ingest

When you only need to record usage (no pre-check), post it directly:

```bash
curl -s -X POST http://localhost:8000/api/v1/usage \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{ "org": "org_123", "meter": "events.ingested", "quantity": 42 }'
```

The event log dedups within `CBOX_BILLING_DEDUP_WINDOW_DAYS`; reconciliation trues the
ledger up on the 15-minute cadence.

## Related documentation

- [API → Enforcement](../api/enforcement.md)
- [Concepts → Metering & enforcement](../concepts/metering-and-enforcement.md)
