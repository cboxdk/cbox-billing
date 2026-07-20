---
title: Operations
description: Day-two operations — running migrations, the queue worker, and the scheduler; the full lifecycle-command cadence; secrets handling; and the health/telemetry observability surface.
weight: 74
---

# Operations

Running Cbox Billing in production means three long-lived concerns beyond the web
process: **migrations**, the **queue worker**, and the **scheduler** — plus
observability.

## Migrations

Apply schema at deploy time, once per release (not per replica):

```bash
composer deploy               # migrate --force + caches + publish-manifest
# or just:
php artisan migrate --force
```

Migration applies the app's migrations and every installed plugin's automatically.

### Release note — document sequences are rebuilt

This release rebuilds `invoice_sequences` and `credit_note_sequences` to widen
their primary key from `(seller)` to `(seller, environment)`, and runs a data fix
that gives sellers in **non-production** planes a plane-distinct `invoice_prefix`
(see [Invoicing → numbering is plane-distinct](../concepts/invoicing-and-tax.md#numbering-is-plane-distinct)).

- Both tables are tiny (one row per seller per plane) and the rebuild is a
  rename → create → copy → drop inside the migration's transaction.
- **Pause invoice finalization while it runs.** Numbers are drawn under a row lock
  on these tables; a concurrent finalize would block on the rebuild.
- Production numbering is not touched — no prefix change, no counter change, no gap.
- Sandbox planes that were cloned before this release change prefix, so their
  number series visibly changes stem. Nothing is renumbered or reissued.
- The prefix data fix is deliberately **not reversible** (reverting would re-create
  the cross-plane collision); the sequence rebuild is.

## The queue worker

Lifecycle notifications and per-org jobs run on the queue, so a worker must run:

```bash
php artisan queue:work --tries=1
```

Run it as its own deployment/process. Queued work includes transactional mail
(invoice issued, receipt, dunning, renewal reminder, license delivery) and the per-org
jobs (invoice, renew, convert-trial, dunning, retry-payment, issue-license,
reconcile).

## The scheduler

Run the scheduler so the lifecycle cadence fires (cron entry or sidecar calling
`php artisan schedule:run` every minute). The schedule (`routes/console.php`):

| Command | Cadence | Purpose |
| --- | --- | --- |
| `billing:reconcile-active` | every 15 min | Convergent delta reconcile of hot-path usage into the ledger. |
| `billing:apply-scheduled-changes` | hourly | Apply deferred (period-end) plan changes as they come due. |
| `billing:renew` | daily 03:00 | Cycle renewal — grant allotments, advance period, renew add-ons, issue renewal invoice. |
| `billing:issue-licenses` | daily 03:30 | Reissue on-prem licenses for active licensable subscriptions. |
| `billing:convert-trials` | daily 04:00 | Convert due trials to paying Active; send trial-ending reminders. |
| `billing:invoice` | monthly, 1st 02:00 | The monthly invoicing pass. |
| `billing:dunning` | daily 06:00 | Access-gating delinquency dunning. |
| `billing:retry-payments` | daily 06:30 | Smart-retry dunning for failed renewal charges. |

Every command uses `withoutOverlapping`, and each is idempotent and time-keyed, so
running more often only tightens freshness — it never double-applies.

### Related console commands (manual)

| Command | Purpose |
| --- | --- |
| `billing:token` | Issue an API bearer token (operator / per-org / product-scoped). |
| `billing:license-keygen` | Generate the Ed25519 issuer keypair (prints only). |
| `cbox-id:publish-manifest` | Publish the RBAC manifest to Cbox ID. |

Several scheduled commands accept `--org=` to limit a run to one organization.

## Secrets handling

- Keep `APP_KEY`, DB creds, mailer creds, gateway keys, and the license **signing**
  key in the secret store — never in git. The signing key is never logged and only
  the public key is ever displayed.
- The commercial build's `composer_auth` token is a build-time BuildKit secret, never
  a layer.

See [Security → Posture](../security/posture.md).

## Observability

### Health (`cboxdk/laravel-health`)

`/health` probes live under the `health` prefix:

- **Liveness** (`/health`) and **readiness** (`/health/ready`) are **public** — an
  orchestrator's probes hit them unauthenticated, and they expose only up/down plus
  check names. Readiness checks DB + cache + queue + storage.
- **Detail** endpoints (`/health/status`, `/health/metrics`, `/health/metrics/json`)
  are **token-gated** by `HEALTH_TOKEN` (optionally IP-restricted with
  `HEALTH_ALLOWED_IPS`).

### Telemetry (`cboxdk/laravel-telemetry`)

Collector-free metrics/traces/events, **off by default**. Enable with
`TELEMETRY_ENABLED=true` and give it a metric store (`TELEMETRY_STORE=redis`
recommended). Scrape `/telemetry` and/or route logs through the `telemetry` channel.

### Logging

Set `LOG_CHANNEL=json` in containers for one-line JSON on stdout a collector ingests
without a parser.

## Related documentation

- [Production checklist](production-checklist.md)
- [Configuration → Queue, cache & session](../configuration/queue-cache-session.md)
- [Security → Posture](../security/posture.md)
