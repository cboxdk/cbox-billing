---
title: Environment reference
description: Every environment variable Cbox Billing reads — app, database, billing engine, gateways, identity, licensing, mail, rate limits, CORS, health, and telemetry — grouped by concern.
weight: 21
---

# Environment reference

Every variable below is read by the app or one of its config files. The canonical
starting point is the committed `.env.example`, which teaches production-safe
defaults. This page groups the keys by concern and explains what each does.

## App

| Key | Default | Notes |
| --- | --- | --- |
| `APP_NAME` | `Cbox Billing` | Shown in the console and mail. |
| `APP_ENV` | `production` | Set `local` for development. |
| `APP_KEY` | — | `php artisan key:generate`. Required. |
| `APP_DEBUG` | `false` | Keep `false` in production. |
| `APP_URL` | `https://billing.example.com` | The canonical base URL. |
| `LOG_CHANNEL` | `json` | `json` = one-line JSON on stdout for a collector; `stack` = files; `stderr` = plain text. |
| `LOG_LEVEL` | `info` | Standard Laravel log level. |

## Database

| Key | Default | Notes |
| --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | `pgsql` (recommended) or `mysql` in production; `sqlite` local-only. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | MySQL uses `3306`. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `cbox_billing` / `cbox_billing` / — | For SQLite, `DB_DATABASE` defaults to `database/database.sqlite`. |

## Billing engine — durable stores

The engine defaults these to in-memory; the app binds them durable. Keep them
`database` (they are also bound explicitly in `BillingServiceProvider`).

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_BILLING_CURRENCY_LOCK_STORE` | `database` | Per-account billing-currency lock. |
| `CBOX_BILLING_EVENT_LOG` | `database` | The immutable usage event log (metering source of truth). A ClickHouse adapter is optional at scale. |
| `CBOX_BILLING_RECONCILE_CHECKPOINT` | `database` | Per-entity reconciliation checkpoints. |
| `CBOX_BILLING_WALLET_STORE` | `database` | The organization credit wallet. |
| `CBOX_BILLING_DEFAULT_CURRENCY` | `DKK` | Last-resort fallback only; the currency lock is the authority once an account transacts. |

## Billing behaviour knobs

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_BILLING_TRIAL_DAYS` | `14` | Default trial length. |
| `CBOX_BILLING_TRIAL_REQUIRE_PM` | `false` | Require a vaulted payment method before a trial converts. |
| `CBOX_BILLING_TRIAL_NO_PM_ACTION` | `cancel` | `cancel` or `pause` a due trial with no method (only when the above is true). |
| `CBOX_BILLING_RENEWAL_REMINDER_DAYS` | `7` | Lead days for the renewal-reminder email. |
| `CBOX_BILLING_TRIAL_REMINDER_DAYS` | `3` | Lead days for the trial-ending email. |
| `CBOX_BILLING_REACTIVATION_WINDOW_DAYS` | `30` | Win-back window after cancellation. |
| `CBOX_BILLING_DUNNING_MAX_DAYS` | `30` | Delinquency days before suspension is allowed. |
| `CBOX_BILLING_DUNNING_MIN_NOTICES` | `3` | Reminders that must go out before suspension. |
| `CBOX_BILLING_DUNNING_NOTICE_DAYS` | `7` | Minimum gap between reminders. |
| `CBOX_BILLING_DUNNING_GRACE_HOURS` | `24` | Just-missed grace before dunning. |
| `CBOX_BILLING_RETRY_TERMINAL_ACTION` | `cancel` | `cancel` or `none` when the smart-retry schedule is exhausted. |

## Metering & enforcement

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_BILLING_LEASE_SIZE` | `100` | Units requested per lease refill. |
| `CBOX_BILLING_INFRA_FAILURE` | `allow` | Enforcement fail-open (`allow`) or fail-closed (`deny`) on a dependency outage. Semantics always fail closed. |
| `CBOX_BILLING_DEDUP_WINDOW_DAYS` | `32` | Usage-event dedup window. |
| `CBOX_BILLING_RECONCILE_INGEST_LAG` | `60` | Seconds of ingest-lag clamp. |
| `CBOX_BILLING_RECONCILE_WINDOW_DAYS` | `32` | Beyond this, usage is bucketed `aged_out`, never dropped. |
| `CBOX_BILLING_RECONCILE_CURRENCY` | `EUR` | The allowance denomination deltas are carried in. |
| `CBOX_BILLING_LEASE_TTL` / `CBOX_BILLING_RESERVATION_TTL` | `300` / `300` | Lease and reservation TTL (seconds). |
| `CBOX_BILLING_ROLLOUT_CHUNK_SIZE` | `500` | Orgs written per transaction on a plan-wide entitlement rollout. |

## API auth & rate limits

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_BILLING_API_TOKEN` | — | An optional static operator bearer token (any org, no DB row). Leave unset in multi-tenant setups; issue per-org tokens instead. |
| `CBOX_BILLING_THROTTLE_ENFORCEMENT` | `600` | Enforcement hot-path req/min per token. |
| `CBOX_BILLING_THROTTLE_MANAGEMENT` | `60` | Management surface req/min per token. |
| `CBOX_BILLING_THROTTLE_WEBHOOK` | `120` | Inbound webhook req/min per source IP. |

## Payment gateways

| Key | Default | Notes |
| --- | --- | --- |
| `STRIPE_SECRET` | — | When set, the Stripe gateway binds as the `PaymentGateway`; otherwise the manual gateway is the fallback. |
| `STRIPE_PUBLISHABLE` | — | Browser-safe key for the payment element. |
| `STRIPE_WEBHOOK_SECRET` | — | Verifies inbound Stripe webhooks. |
| `CBOX_BILLING_WEBHOOK_SECRET` | — | The manual settlement webhook secret. Deny-by-default: no secret ⇒ every payload refused. |
| `CBOX_BILLING_WEBHOOK_SIGNATURE_HEADER` | `X-Cbox-Signature` | The header the manual verifier reads. |
| `CBOX_BILLING_HOSTED_SESSION_TTL` | `30` | Hosted checkout/portal token TTL (minutes). |
| `CBOX_BILLING_UPGRADE_RETURN_URL` | app root | Where a hosted checkout returns after settlement (used by upgrade deep-links). |

See [Payment gateways](payment-gateways.md).

## Seller & currency

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_BILLING_SELLER` | `cbox-dk` | The default selling entity of record. |

Seller entities themselves are declared in `config/billing.php` → `seller.entities`.
See [Tax & seller entities](tax-and-sellers.md).

## Identity (Cbox ID)

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_ID_ISSUER` | — | The Cbox ID base URL. Empty ⇒ local/demo mode (no live provider). |
| `CBOX_ID_CLIENT_ID` / `CBOX_ID_CLIENT_SECRET` | — | OAuth client credentials. |
| `CBOX_ID_REDIRECT_URI` | — | The OIDC callback URL (read by `config/services.php` for the login flow). |
| `CBOX_ID_SCOPES` | `openid profile email` | Scopes requested at login. |
| `CBOX_ID_HTTP_TIMEOUT` / `CBOX_ID_CACHE_TTL` | `10` / `3600` | Back-channel timeout and discovery/JWKS cache. |

> Both the login flow (`config/services.php`) and the RBAC-manifest config
> (`config/cbox-id-client.php`) read `CBOX_ID_REDIRECT_URI` for the OIDC callback URL.
> The manifest publish itself does not depend on the redirect value. See
> [Identity](../identity/_index.md).

## On-prem licensing (issuer + consume)

| Key | Default | Notes |
| --- | --- | --- |
| `CBOX_LICENSE_SIGNING_KEY` | — | Base64 Ed25519 **private** key that signs licenses + revocation lists. A secret — never commit or log it. Empty ⇒ licensing inert. |
| `CBOX_LICENSE_PUBLIC_KEY` | — | Matching **public** key; safe to share, bundle in downstream deployments to verify offline. |
| `CBOX_LICENSE_VALIDITY_DAYS` | `365` | Default issued-license window (when not subscription-derived). |
| `CBOX_LICENSE_GRACE_DAYS` | `14` | Offline grace buffer past expiry. |
| `CBOX_LICENSE_CLOCK_SKEW` | `60` | Verifier clock-skew tolerance (seconds). |
| `CBOX_BILLING_LICENSE_KEY` | — | The **consume**-license this deployment installs to unlock its own bundled plugins. Empty ⇒ free tier. |
| `CBOX_BILLING_DEPLOYMENT_ID` | — | This deployment's stable id, matched against the consume-license binding. |

See [Licensing](../concepts/licensing.md) and [Open core → Capability gating](../open-core/capability-gating.md).

## Mail

Standard Laravel mail (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`). Lifecycle notifications
(invoice issued, receipt, dunning, renewal reminder, subscription changed, license
delivery) are queued and sent through this mailer. `log` writes them to the log
instead of delivering.

## Queue, cache & session

Local defaults are `database` for `QUEUE_CONNECTION`, `CACHE_STORE`, and
`SESSION_DRIVER`. In production move all three to `redis`. See
[Queue, cache & session](queue-cache-session.md).

## CORS

| Key | Default | Notes |
| --- | --- | --- |
| `CORS_ALLOWED_ORIGINS` | — (empty) | Comma-separated exact origins. Empty ⇒ no cross-origin browser access. |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | — | Optional regex patterns. |
| `CORS_SUPPORTS_CREDENTIALS` | `false` | — |
| `CORS_MAX_AGE` | `3600` | Preflight cache. |

See [CORS & throttling](cors-and-throttling.md).

## Observability

| Key | Default | Notes |
| --- | --- | --- |
| `HEALTH_ENABLED` | `true` | Enables the `/health` endpoints. |
| `HEALTH_TOKEN` | — | Gates the detail endpoints (`/health/status`, `/health/metrics`, `/health/metrics/json`). Liveness + readiness stay public. |
| `HEALTH_ALLOWED_IPS` | — | Optional IP allow-list. |
| `HEALTH_PUBLIC_ENDPOINTS` | `liveness,readiness` | Which endpoints are public. |
| `TELEMETRY_ENABLED` | `false` | Collector-free metrics/traces/events. |
| `TELEMETRY_STORE` | — | `redis` (recommended) or `apcu` when enabled. |

See [Deployment → Operations](../deployment/operations.md).

## Related documentation

- [Payment gateways](payment-gateways.md)
- [Queue, cache & session](queue-cache-session.md)
- [Identity](../identity/_index.md)
