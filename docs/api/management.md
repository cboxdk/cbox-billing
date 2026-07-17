---
title: Management API
description: The self-service management surface — plans, organizations, subscribe/preview/change/cancel/pause/resume/reactivate/quantity/add-ons, usage, invoices, payment methods, checkout/portal sessions, embedded intents, and licenses — with idempotency.
weight: 53
---

# Management API

The management API is the self-service, mutating surface the SDK's management client
and the hosted portal drive. Same bearer-token auth and per-org scope as the
enforcement API, but throttled at the lower `cbox-management` tier (60/min) and — for
writes that must not double-apply — gated by an `Idempotency-Key` header.

All routes are under `/api/v1`. Writes marked **idempotent** carry the `idempotency`
middleware.

## Plans & organizations

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/plans` | The catalog the token may sell (product-scoped tokens see only their product). |
| `PUT` | `/organizations/{org}` | Idempotent upsert — merchant platforms provision the orgs they bill for on demand. |

## Subscriptions

| Method | Path | Idempotent | Notes |
| --- | --- | :---: | --- |
| `GET` | `/subscriptions/{org}` | — | The org's active subscription, or 404. |
| `POST` | `/subscriptions` | ✓ | `{org, plan, seats?, currency?, trial?, trial_days?}`. Opens a trial when `trial` or `trial_days` is set. |
| `POST` | `/subscriptions/{org}/preview` | — | `{plan}` — the consequence of a change, uncommitted (preview equals charge). |
| `POST` | `/subscriptions/{org}/change` | ✓ | `{plan, when?}` — `now` (default) or `period_end` (deferred). |
| `POST` | `/subscriptions/{org}/cancel` | — | `{mode?, at_period_end?, reason?, feedback?}` — `immediate` / `period_end` / `pause`. |
| `POST` | `/subscriptions/{org}/reactivate` | — | Win-back; 409 when not in a reactivatable state. |
| `POST` | `/subscriptions/{org}/pause` · `/resume` | — | Suspend / lift access + metering. |
| `POST` | `/subscriptions/{org}/quantity` | ✓ | `{seats, preview?}` — prorated seat change. |
| `POST` | `/subscriptions/{org}/addons` | ✓ | Attach an aligned/independent add-on (or `preview`). |
| `DELETE` | `/subscriptions/{org}/addons/{key}` | — | Detach an add-on. |

### Preview shapes

A change/preview returns the prorated consequence — `due_now_minor`, `credit_minor`,
`new_recurring_minor`, `effective_at`, a `credit_delta`
(`forfeited`/`granted`/`carried`), and the proration `lines`. A deferred change adds
`scheduled: true` with its `effective_at`. A quantity change returns `from_seats`,
`to_seats`, `due_now_minor` or `credit_minor`, and `applied`. See
[Subscriptions & lifecycle](../concepts/subscriptions-and-lifecycle.md).

## Usage & invoices

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/usage/{org}` | The org's metered usage summary. |
| `GET` | `/invoices/{org}` | The org's invoices. |

## Hosted sessions & embedded intents

Two integration paths for payment (both surfaced here):

**Path A — hosted pages.** Each returns the `{url}` of a hosted page keyed by an
opaque, expiring session token (the URL, not a provider auth gate, authorizes it):

| Method | Path |
| --- | --- |
| `POST` | `/checkout-sessions` |
| `POST` | `/portal-sessions` |

**Path B — embedded intents.** A product mounts the gateway's own element and
confirms client-side against the client secret these return. Intents are created
against the **gateway customer handle** (`cus_…`), never the raw org id:

| Method | Path |
| --- | --- |
| `POST` | `/setup-intents` |
| `POST` | `/payment-intents` |
| `GET` | `/payment-methods/{org}` |
| `POST` | `/payment-methods/{org}/default` |
| `DELETE` | `/payment-methods/{org}/{id}` |

See [Hosted checkout & portal](hosted-checkout-and-portal.md).

## Licenses (operator-authed)

| Method | Path | Idempotent | Notes |
| --- | --- | :---: | --- |
| `POST` | `/licenses` | ✓ | Issue a signed, offline-verifiable license for a customer + licensable plan. |
| `POST` | `/licenses/{id}/renew` | — | Reissue with an extended window. |
| `POST` | `/licenses/{id}/revoke` | — | Add to the signed revocation list. |

See [Licensing](../concepts/licensing.md) and
[License activation](license-activation.md) (the separate, unauthenticated refresh
path).

## Idempotency

Send an `Idempotency-Key` on the idempotent writes above. A retry with the same key
returns the original outcome instead of re-applying (so a retried subscribe or
license issue cannot double-apply). See [API overview → Idempotency](_index.md).

## Related documentation

- [Concepts → Subscriptions & lifecycle](../concepts/subscriptions-and-lifecycle.md)
- [Hosted checkout & portal](hosted-checkout-and-portal.md)
- [Authentication](authentication.md)
