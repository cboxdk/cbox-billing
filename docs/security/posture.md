---
title: Posture
description: The application-layer security posture — deny-by-default across auth, gateways, CORS and licensing; secrets handling; webhook signature verification; per-org tenant scoping; and the supply-chain gate.
weight: 91
---

# Posture

## Deny-by-default, everywhere

The consistent rule: an unset secret **disables** the thing it gates, never opens it.

| Surface | With nothing configured |
| --- | --- |
| API auth (`api.token`) | No matching token → 401. |
| Per-org scope | A token for org A acting on org B → 403. |
| Webhook verification | No secret → the verifier **refuses every payload**. |
| CORS | No allow-list → no cross-origin browser access (never `*`). |
| Capability gate | No consume-license → all commercial capabilities locked (free tier). |
| Licensable plans | A plan absent from `licensing.profiles` → cannot be minted. |
| Enforcement (semantics) | Unknown/disabled meter or exhausted allowance → denied. |
| Console features | A feature never registered → page hidden, routes 404. |

Enforcement on a **dependency outage** is the one deliberately tunable case: it
fails **open** by default (`CBOX_BILLING_INFRA_FAILURE=allow`) so an infra blip does
not throttle paid traffic — the durable ledger reconciles the truth — or fails closed
(`deny`) for strict tenants. Semantics always fail closed. See
[Metering & enforcement](../concepts/metering-and-enforcement.md).

## Secrets handling

- **Never in git.** `APP_KEY`, DB creds, mailer creds, gateway keys, and the license
  **signing** key go in the environment / secret store. `.env` is gitignored;
  `.env.example` carries only empty placeholders.
- **The signing key is never logged and never displayed.** Only the license **public**
  key is ever shown (Licenses → Distribution, for offline hand-off).
- **Build secrets are not layers.** The commercial composition's `composer_auth`
  GitHub token is a BuildKit tmpfs mount, present only for the `composer require`
  layer — never written into an image layer.
- **No secrets in URLs.** Hosted checkout/portal use opaque, expiring session tokens;
  the enforcement/management APIs use bearer tokens in the `Authorization` header.

## Webhook signature verification

Inbound payment webhooks (`/webhooks/{gateway}`) are **public** — they carry no
bearer token — because authenticity is proved by the **gateway signature** the bound
`WebhookVerifier` checks:

- The manual verifier is deny-by-default: no `CBOX_BILLING_WEBHOOK_SECRET` → every
  payload refused.
- The Stripe adapter binds its own verifier from `STRIPE_WEBHOOK_SECRET`.
- Ingest is **exactly-once** (durable `ProcessedEventStore` / `SettledPaymentStore`),
  so a re-delivery is a safe no-op.
- Do **not** disable signature verification.

The webhook path is rate-limited per source IP (`cbox-webhook`) so a flood of forged
callbacks cannot exhaust the settlement path.

## Tenant scoping

Every enforcement and management controller calls `denyUnlessMayActFor($request,
$org)` — the authenticated token's identity must be allowed to act for the target org,
or the request is refused (403). A product-scoped token additionally refuses another
product's plan. One tenant's token can never read or mutate another's data, even
though all tenants share the same `/api/v1` surface. See
[API → Authentication](../api/authentication.md).

## Operating securely

- Keep `APP_DEBUG=false` in production; terminate TLS in front of the app.
- Hold gateway keys and webhook secrets in the environment, never in git.
- Keep the durable stores on the database (they are the money source of truth).

## The supply chain is gated

CI enforces a permissive-only dependency license policy
(`composer license-check` → MIT/BSD/Apache/ISC/0BSD), `composer audit` for known
advisories, and a drift-checked CycloneDX SBOM (`composer sbom`). See
[Getting started → Running the tests](../getting-started/testing.md).

## Related documentation

- [Documented seams](documented-seams.md)
- [Configuration → CORS & throttling](../configuration/cors-and-throttling.md)
- [Reporting a vulnerability](reporting.md)
