---
title: Production checklist
description: The ordered path to a working production deployment — real secrets, Postgres, Redis, the payment gateway, the license issuer keys, the RBAC manifest, and an end-to-end smoke test.
weight: 73
---

# Production checklist

The open app deploys from a clean checkout; a real deployment adds infrastructure and
secrets you provision. Work through these in order. (For the commercial composition,
also read [Cloud composition](cloud-composition.md).)

## 1. Real secrets

Supply these via your secret store (never git):

- `APP_KEY` — `php artisan key:generate --show`.
- Database credentials, mailer credentials.
- The license issuer keys (`CBOX_LICENSE_SIGNING_KEY` / `CBOX_LICENSE_PUBLIC_KEY`).

The open app ships SQLite + blank keys for **local dev only**.

## 2. Database — Postgres (system of record)

Provision Postgres (recommended) and point `DB_*` at it. Keep the durable billing
stores on `database` (`CBOX_BILLING_EVENT_LOG`, `_RECONCILE_CHECKPOINT`,
`_CURRENCY_LOCK_STORE`, `CBOX_BILLING_WALLET_STORE`). Run the deploy step:

```bash
composer deploy   # migrate --force + config/route/view cache + publish-manifest
```

Migration picks up the app's and every installed plugin's migrations automatically.

## 3. Redis / Valkey

Provision Redis/Valkey and set `SESSION_DRIVER`, `CACHE_STORE`, and
`QUEUE_CONNECTION` to `redis`. Harden the session
(`SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`). See
[Queue, cache & session](../configuration/queue-cache-session.md).

## 4. Queue workers + scheduler

Run the queue worker as its own deployment (`php artisan queue:work`) — invoice /
receipt / dunning notifications, connector exports, and reconcile all run on the
queue. Run the scheduler (`php artisan schedule:run` every minute, via cron or a
sidecar) so the lifecycle cadence fires. See [Operations](operations.md).

## 5. Payment gateway

Set the Stripe keys + `STRIPE_WEBHOOK_SECRET` (or the manual
`CBOX_BILLING_WEBHOOK_SECRET`) and confirm the intended gateway binds (Stripe, not the
manual fallback). Point the gateway webhook at `/webhooks/{gateway}`. See
[Payment gateways](../configuration/payment-gateways.md).

## 6. License issuer

Generate the Ed25519 pair (`php artisan billing:license-keygen`) and set
`CBOX_LICENSE_SIGNING_KEY` (secret) + `CBOX_LICENSE_PUBLIC_KEY`. This is the plane
that mints entitlements. For a **composed** deployment, the plugins unlock from the
installed **consume**-license (`CBOX_BILLING_LICENSE_KEY` + `CBOX_BILLING_DEPLOYMENT_ID`),
not from env flags — see [Capability gating](../open-core/capability-gating.md).

## 7. Cbox ID + RBAC manifest

Set `CBOX_ID_ISSUER` + `CBOX_ID_CLIENT_ID` / `CBOX_ID_CLIENT_SECRET` and
`CBOX_ID_REDIRECT_URI`. Grant the billing OAuth client the **`apps.manifest`** scope
(Cbox ID console → Developers → Apps). `composer deploy` runs
`cbox-id:publish-manifest` (idempotent) to declare Billing's roles + permissions. See
[Federated RBAC manifest](../identity/rbac-manifest.md).

## 8. Ingress & TLS

Terminate TLS in front of the app, set `APP_URL`, and configure `TRUSTED_PROXIES` for
your ingress. Keep `APP_DEBUG=false`.

## 9. Observability

Set `LOG_CHANNEL=json` for one-line JSON on stdout. Wire liveness/readiness probes to
`/health` and `/health/ready`; gate the detail endpoints with `HEALTH_TOKEN`. Enable
telemetry if you scrape it. See [Operations](operations.md).

## 10. Smoke test end-to-end

Confirm sign-in works, an invoice flows issue → notification, a `reserve`/`commit`
round-trip succeeds, and — for the composed image with entitlements delivered — the
Reseller / Revenue-recognition / Connectors / Tax-filing console areas appear and a
prepared VAT/OSS filing renders.

## Related documentation

- [Operations](operations.md)
- [Cloud composition](cloud-composition.md)
- [Configuration → Environment](../configuration/environment.md)
