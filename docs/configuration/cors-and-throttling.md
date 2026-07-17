---
title: CORS & throttling
description: The deny-by-default CORS allow-list for the browser-facing surfaces and the two-tier, per-token API rate limits plus the webhook ceiling.
weight: 25
---

# CORS & throttling

## CORS

Cross-origin browser access is **deny-by-default**. `config/cors.php` builds its
allow-list from an explicit, env-driven list — never the `*` wildcard.

```dotenv
CORS_ALLOWED_ORIGINS=https://app.acme.com,https://dashboard.acme.com
# CORS_ALLOWED_ORIGINS_PATTERNS=
# CORS_SUPPORTS_CREDENTIALS=false
```

- With `CORS_ALLOWED_ORIGINS` **unset**, no cross-origin browser request is allowed.
- The allow-list applies to the `api/*`, `billing/*`, and `webhooks/*` paths.
- Allowed methods: `GET, POST, DELETE, OPTIONS`.
- Allowed headers include `Authorization`, `Content-Type`, and **`Idempotency-Key`**
  (so a browser can send the idempotency header on mutating management calls).

Server-to-server SDK traffic (the enforcement API) carries a **bearer token** and is
**not subject to CORS at all** — CORS only governs browser origins, e.g. a product's
own dashboard embedding the payment element.

## Rate limiting

Per-token API rate limits are defined in `config/billing.php` → `rate_limits` and
registered as named limiters in `AppServiceProvider`, applied as `throttle:cbox-*`
on the route groups. There are **two tiers plus the webhook**:

| Limiter | Applies to | Env | Default (req/min) |
| --- | --- | --- | --- |
| `cbox-enforcement` | The hot path: `reserve`, `commit`, `usage`, `leases`, `entitlements`. Runs on every metered operation, so the higher ceiling. | `CBOX_BILLING_THROTTLE_ENFORCEMENT` | `600` |
| `cbox-management` | The self-service surface: subscriptions, payment intents, invoices, licenses. Human-paced and mutating, so the lower ceiling. | `CBOX_BILLING_THROTTLE_MANAGEMENT` | `60` |
| `cbox-webhook` | Inbound settlement callbacks from the gateway. | `CBOX_BILLING_THROTTLE_WEBHOOK` | `120` |

### How the key is derived

Each limiter is keyed **per bearer token** (`token:<sha256>`), so one tenant's
traffic can never exhaust another's budget. A token-less request falls back to the
client IP (`ip:<addr>`). The webhook limiter keys on the **source IP**, since the
callback carries no bearer token — its authenticity is the gateway signature.

### The activation heartbeat has its own limiter

The unauthenticated license-activation heartbeat (`GET /api/v1/license/activate`)
keeps its own inline `throttle:30,1` so it cannot be probed. See
[API → License activation](../api/license-activation.md).

## Related documentation

- [API → Authentication](../api/authentication.md)
- [API → Throttling & idempotency](../api/_index.md)
- [Security → Posture](../security/posture.md)
