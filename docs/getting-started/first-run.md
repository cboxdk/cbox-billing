---
title: First run & seed data
description: Seed a demo catalog, licensing profiles, and a first organization with migrate:fresh --seed, then sign in with demo mode — exactly what each seeder creates.
weight: 12
---

# First run & seed data

A fresh database is empty. Seed it with a realistic demo catalog so every console
screen and API endpoint has real rows to project from.

```bash
php artisan migrate:fresh --seed
```

`migrate:fresh` drops and re-creates every table; `--seed` runs `DatabaseSeeder`,
which creates a test user and then calls three seeders.

## What the seeders create

### `CatalogSeeder` — the demo product and plans

One product (**Cbox Billing**) with a four-plan monthly ladder. Each plan is priced
in **DKK + EUR + USD** (integer minor units), carries a recurring **included-credit
grant**, and declares **per-meter entitlements**.

Four metered dimensions are seeded: `api.requests`, `seats`, `storage.gb`,
`events.ingested`.

| Plan | EUR / mo | Included credits | api.requests | seats | storage.gb | events.ingested |
| --- | --- | --- | --- | --- | --- | --- |
| **Starter** | €39.00 | 50,000 | 100k (bill overage) | 3 (block) | 10 GB (block) | disabled |
| **Team** | €169.00 | 250,000 | 1M (bill) | 10 (bill) | 100 GB (block) | 500k (bill) |
| **Business** | €469.00 | 1,000,000 | 5M (bill) | 50 (bill) | 1,000 GB (block) | 5M (bill) |
| **Scale** | €1,329.00 | 5,000,000 | unlimited | unlimited | 10,000 GB (block) | unlimited |

Each entitlement is a projection-ready meter policy: `enabled`, `allowance`, a
per-unit `multiplier` (overage weight), `unlimited`, and an `overage` behaviour
(`Bill` or `Block`). See [Catalog & pricing](../concepts/catalog-and-pricing.md).

The seeder also gives one plan per **tiered pricing model** a real tier schedule so
the catalog console renders tier tables:

- **Team** → `graduated` (each seat slice billed at its own tier's rate).
- **Business** → `volume` (every seat billed at the single tier the total lands in).
- **Scale** → `package` (a block price per pack of 10 seats).
- **Starter** → `stairstep` (one flat price for the whole seat bracket).

The base `price_minor` is the plan's list "from" price shown in the catalog; a tiered
plan's actual recurring charge (invoice, MRR, and change preview alike) is priced from
its tier schedule via the engine, so e.g. seeded Team (graduated, first 10 seats free)
bills nothing until the 11th seat.

### `LicensingSeeder` — on-prem licensing profiles

Seeds the licensable-plan data behind the on-prem licensing profiles (see
`config/billing.php` → `licensing.profiles`: `enterprise-onprem`, `team-onprem`)
so the [Licenses console](console-tour.md) and the
[license issue flow](../cookbook/issue-a-license.md) have plans to mint from.

### `OrganizationSeeder` — a first billing organization

Seeds a billing organization so the console customer list and the API have an org
to act on. One billing account maps to one identity organization (see
[org-level entitlements](../identity/entitlements.md)).

## Signing in

With **no** `CBOX_ID_ISSUER` set (the local default), the login screen shows a
**demo sign-in** button. It creates a local operator session — no live identity
provider needed — and lands on the dashboard. The provider console is a single
operator surface, so any authenticated session administers it.

Once you configure a real Cbox ID instance, demo sign-in disappears and the OIDC
authorization-code + PKCE flow takes over. See [OIDC login](../identity/oidc-login.md).

## Re-seeding

The seeders use `updateOrCreate`, so re-running `db:seed` is idempotent — it
refreshes the demo rows without duplicating them. Use `migrate:fresh --seed` when
you want a clean slate.

## Related documentation

- [Console tour](console-tour.md)
- [Catalog & pricing](../concepts/catalog-and-pricing.md)
- [Cookbook → Onboard a customer](../cookbook/onboard-a-customer.md)
