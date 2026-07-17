---
title: Queue, cache & session
description: The local database drivers vs Redis/Valkey in production, the queued lifecycle jobs, and the durable billing stores the app binds regardless of driver.
weight: 24
---

# Queue, cache & session

Cbox Billing follows standard Laravel driver configuration, with production-oriented
defaults in `.env.example`.

## The drivers

| Concern | Env | Local default | Production |
| --- | --- | --- | --- |
| Queue | `QUEUE_CONNECTION` | `database` | `redis` |
| Cache | `CACHE_STORE` | `database` | `redis` |
| Session | `SESSION_DRIVER` | `database` | `redis` |
| Broadcast | `BROADCAST_CONNECTION` | `log` | `log` |
| Filesystem | `FILESYSTEM_DISK` | `local` | `local` (or S3) |

For local development the `database` drivers are zero-extra-infrastructure. In
production, move all three to Redis/Valkey:

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=valkey
REDIS_PORT=6379
REDIS_PASSWORD=
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

### Session hardening (production)

```dotenv
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_LIFETIME=120
```

## The queue matters here

The lifecycle notifications and several billing operations run on the queue, so a
worker must be running in any real deployment:

- Transactional mail (invoice issued, receipt, dunning, renewal reminder, license
  delivery) — queued Mailables.
- Per-org jobs: `IssueSubscriptionInvoiceJob`, `RenewSubscriptionJob`,
  `ConvertTrialJob`, `RunOrgDunningJob`, `RetryPaymentJob`, `IssueOrgLicenseJob`,
  `ReconcileOrgUsageJob`.
- (In a composed deployment) connector export jobs.

Run the worker as its own process/deployment:

```bash
php artisan queue:work        # production
php artisan queue:listen      # development (picks up code changes)
```

See [Deployment → Operations](../deployment/operations.md) for running the worker
and the scheduler.

## The enforcement cache

The hot-path enforcement store is the Laravel **cache** (an atomic local counter
per node — see the three-layer model in [Metering & enforcement](../concepts/metering-and-enforcement.md)).
In production this should be a fast store (Redis/Valkey or APCu). Reservations are
held in the same cache with the `CBOX_BILLING_RESERVATION_TTL` TTL.

## The durable billing stores are independent of the cache

Do not confuse the enforcement cache with the **money source of truth**. The ledger,
event log, reconciliation checkpoints, currency lock, and wallet are bound to the
**database** by `BillingServiceProvider` regardless of `CACHE_STORE`, so nothing
that owes money lives only in process memory. The relevant env keys
(`CBOX_BILLING_EVENT_LOG`, `CBOX_BILLING_RECONCILE_CHECKPOINT`,
`CBOX_BILLING_CURRENCY_LOCK_STORE`, `CBOX_BILLING_WALLET_STORE`) all default to
`database`.

## Related documentation

- [Environment reference](environment.md)
- [Deployment → Operations](../deployment/operations.md)
- [Concepts → Metering & enforcement](../concepts/metering-and-enforcement.md)
