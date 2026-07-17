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
composer setup
```

`composer setup` runs the whole first-time sequence: `composer install`, copy
`.env.example` → `.env` (if absent), generate `APP_KEY`, run migrations, and build
the front-end assets. See its exact steps in [Installation](getting-started/installation.md).

For local development, set these three in `.env` (the template ships
production-safe defaults):

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
```

SQLite is zero-config — `DB_DATABASE` defaults to `database/database.sqlite`, which
the setup scripts create for you. Do **not** run SQLite in production.

## 2. Seed the catalog and an organization

```bash
php artisan migrate:fresh --seed
```

This seeds a demo product with a four-plan ladder (**Starter / Team / Business /
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

## 5. Try the enforcement API

The metered hot path lives under `/api/v1` and is token-authenticated. Issue an
operator token, then reserve and commit against a seeded meter:

```bash
php artisan billing:token "local dev" --org=<org-id>
# → prints the bearer token once

curl -s http://localhost:8000/api/v1/reserve \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"org":"<org-id>","meters":[{"meter":"api.requests","estimate":1}]}'
# → {"outcome":"allowed","reservation_id":"..."}
```

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
