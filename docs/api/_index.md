---
title: API
description: The HTTP API surface of Cbox Billing — the enforcement hot path, the self-service management API, hosted checkout/portal, license activation, plus authentication, throttling, and idempotency.
weight: 50
---

# API

Cbox Billing exposes several HTTP surfaces, each with its own auth model and throttle
tier. All the JSON APIs are versioned under `/api/v1` and rendered as JSON (the app
forces JSON responses for `api/*` and `webhooks/*`).

## The surfaces

| Surface | Prefix | Auth | Throttle |
| --- | --- | --- | --- |
| [Enforcement API](enforcement.md) | `/api/v1` | Bearer token (`api.token`) | `cbox-enforcement` (600/min) |
| [Management API](management.md) | `/api/v1` | Bearer token (`api.token`) | `cbox-management` (60/min) |
| [Hosted checkout & portal](hosted-checkout-and-portal.md) | `/billing` | Opaque session token in the URL | web |
| [Payment webhooks](../concepts/payments-and-dunning.md) | `/webhooks/{gateway}` | Gateway signature | `cbox-webhook` (120/min) |
| [License activation](license-activation.md) | `/api/v1/license` | Deployment id (unauthenticated) | `throttle:30,1` |

The enforcement and management APIs share the same token auth and per-org scope; they
differ in throttle tier and in that the management **writes** honour an
`Idempotency-Key`.

## The contract & SDK

The `/api/v1` surface is described by a hand-authored **OpenAPI 3.1** contract, served live
and kept in lock-step with the routes by a drift test. Read it, or a typed client, before
you write a line of integration:

- `GET /api/openapi.yaml` · `GET /api/openapi.json` — the machine-readable contract.
- `GET /api/docs` — a self-contained HTML reference (also linked from the console command
  palette and Settings → API tokens).
- A typed **[TypeScript SDK](sdk-typescript.md)** under `sdks/typescript/`.

See [OpenAPI spec & live reference](openapi.md) for the full story.

## In this section

| Page | What |
| --- | --- |
| [Authentication](authentication.md) | API tokens (operator, per-org, product-scoped), the static token, and per-org enforcement. |
| [Enforcement API](enforcement.md) | `leases`, `reserve`, `commit`, `usage`, `entitlements`. |
| [Management API](management.md) | plans, organizations, subscriptions, usage, invoices, payment methods, checkout/portal sessions, intents, licenses. |
| [Hosted checkout & portal](hosted-checkout-and-portal.md) | The token-authorized pages and their JSON action endpoints. |
| [License activation](license-activation.md) | The optional, unauthenticated heartbeat. |
| [OpenAPI spec & live reference](openapi.md) | The OpenAPI 3.1 contract, `/api/openapi.{yaml,json}`, `/api/docs`, and the drift guard. |
| [TypeScript SDK](sdk-typescript.md) | The typed client under `sdks/typescript/` — install, config, and transport features. |

## Idempotency

Mutating management writes that must not double-apply carry the `idempotency`
middleware and honour an **`Idempotency-Key`** request header. A retried
subscribe / plan-change / quantity / add-on / license-issue with the same key
returns the original outcome instead of applying twice. Keys are stored in the
`idempotency_keys` table. The routes that require it are noted in
[Management API](management.md).

## Throttling

Limits are per bearer token (`token:<sha256>`), IP as fallback. The three tiers and
their env keys are documented in [Configuration → CORS & throttling](../configuration/cors-and-throttling.md).

## Related documentation

- [Configuration → CORS & throttling](../configuration/cors-and-throttling.md)
- [Identity → Org-level entitlements](../identity/entitlements.md)
