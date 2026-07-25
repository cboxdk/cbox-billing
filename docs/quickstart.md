---
title: Quick start
description: Clone, install, seed, and run Cbox Billing locally in one read — provider console, enforcement API, and demo sign-in with no external services.
weight: 2
---

# Quick start

Zero to a running provider console with a seeded catalog, on SQLite, with **no
external services** — no Postgres, no Redis, no Stripe, no live Cbox ID. This is
the local-development path; the [production checklist](deployment/production-checklist.md)
covers the real deployment.

## Prerequisites

- **PHP 8.4+** with the usual Laravel extensions (see [Requirements](requirements.md)).
- **Composer 2**.
- **Node 18+** and npm (for the front-end assets).

## 1. Clone and set up

```bash
git clone https://github.com/cboxdk/cbox-billing.git
cd cbox-billing
composer setup:local
```

`composer setup:local` runs the whole first-time sequence and leaves you with a
working local install — nothing to edit by hand: `composer install`, copy
`.env.example` → `.env` (if absent), switch it to local development (SQLite,
`APP_ENV=local`, `APP_DEBUG=true`, and the demo operator org allowlisted), generate
`APP_KEY`, migrate, seed a realistic dataset, and build the front-end assets.

It only rewrites the handful of keys that must differ locally, it is safe to re-run,
and it refuses to touch an `.env` that looks like a real environment.

> Use **`composer setup`** (no `:local`) when provisioning a server. That variant
> leaves `.env.example`'s production-safe defaults alone — Postgres, `APP_ENV=production`,
> `APP_DEBUG=false`, and an empty operator allowlist — and expects you to supply
> real values. Running it on a laptop will fail at the migration step, because the
> template points at a Postgres database that does not exist yet.

See the exact steps in [Installation](getting-started/installation.md).

SQLite is zero-config — `DB_DATABASE` defaults to `database/database.sqlite`, which
the setup scripts create for you. Do **not** run SQLite in production.

## 2. The seeded dataset

`composer setup:local` already migrated and seeded, so there is nothing to run here —
this is what you now have (and the command to rebuild it from scratch at any point):

```bash
php artisan migrate:fresh --seed
```

The seed creates a demo product with a four-plan ladder (**Starter / Team / Business /
Scale**), each priced in DKK + EUR + USD, with per-meter entitlements, recurring
included-credit grants, and a tiered price schedule per plan (graduated, volume,
package, stairstep). It also seeds a first organization and the on-prem licensing
profiles. See [First run & seed data](getting-started/first-run.md).

## 3. Run the app

```bash
composer run dev
```

This starts four processes concurrently — `php artisan serve`, the queue listener,
`php artisan pail` (logs), and Vite. The provider console is at
<http://localhost:8000/>.

## 4. Sign in (demo mode)

With **no** `CBOX_ID_ISSUER` configured, the login screen offers a **demo sign-in**
button — a local operator session with no live identity provider. Click it to land
on the dashboard. Once you point `CBOX_ID_ISSUER` at a real Cbox ID instance, demo
sign-in disappears and the OIDC flow takes over (see [OIDC login](identity/oidc-login.md)).

If you get **“Not authorized for this console”** instead of the dashboard, the
operator allowlist is empty. The console is deny-by-default, so it denies every
session until you name an operator organization — `composer setup:local` sets this
for you, but a hand-built `.env` will not have it:

```dotenv
CBOX_BILLING_OPERATOR_ORGS=01demo0org0systems
```

Outside production the error page itself tells you this and fills in the org id from
your own session.

## 5. Try the enforcement API

The metered hot path lives under `/api/v1` and is token-authenticated. Issue an
operator token, then reserve and commit against a seeded meter:

```bash
php artisan billing:token "local dev"
# → prints the bearer token once

curl -s http://localhost:8000/api/v1/reserve \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"org":"org_fjord","meters":[{"meter":"api.requests","estimate":1}]}'
# → {"outcome":"allowed","reservation_id":"..."}
```

**Use `org_fjord` here.** The seeded dataset is deliberately realistic rather than
uniformly happy: most of the eight seeded organizations are already well into their
included allowance, so reserving against them correctly returns

```json
{"outcome":"denied","reason":"quota_exhausted"}
```

That is the enforcement engine working, not a setup problem — `org_fjord` is the one
seeded with headroom. To see the other side, run the same call against `org_hverdag`
and then check `GET /api/v1/usage/org_hverdag` to see how close it is to its ceiling.

Full request/response shapes are in the [enforcement API reference](api/enforcement.md).

## 6. Verify

```bash
composer qa   # pint --test · phpstan · pest · license-check · composer audit
```

See [Running the tests](getting-started/testing.md) for the individual gates.

## Next steps

- [Console tour](getting-started/console-tour.md) — what each area does.
- [Configuration → Environment](configuration/environment.md) — every `CBOX_*` key.
- [Cookbook](cookbook/_index.md) — onboard a customer, author a plan, meter usage,
  issue an invoice, configure Stripe, and more.

## Related documentation

- [Installation](getting-started/installation.md)
- [Requirements](requirements.md)
