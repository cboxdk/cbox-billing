---
title: Getting started
description: Install Cbox Billing locally or on Herd, run it for the first time with seeded data, run the test suite, and tour the provider console.
weight: 10
---

# Getting started

Cbox Billing runs from a clean checkout with a plain `composer install` — every
dependency is public. This section takes you from clone to a running console with
real data, then shows you around.

## In this section

| Page | What |
| --- | --- |
| [Installation](installation.md) | Local install, Laravel Herd, the `composer setup`/`dev`/`test` scripts, and choosing SQLite vs a real database. |
| [First run & seed data](first-run.md) | `migrate:fresh --seed`, exactly what the seeders create, and demo sign-in. |
| [Running the tests](testing.md) | The `composer qa` gate — Pint, PHPStan, PHPUnit, license-check, and audit. |
| [Console tour](console-tour.md) | Every area of the provider console and what it shows. |

## The shape of the app

The app is a standard Laravel 13 project with a few deliberate seams:

- **`app/Billing/*`** — the app-owned billing services (subscriptions, invoicing,
  payments, retention, metering views, licensing, hosted sessions, reporting),
  each behind a contract and bound in `BillingServiceProvider`.
- **`app/Platform/*`** — the console-kit integration (nav IA, current context).
- **`app/Http/Controllers/Api/*`** — the thin enforcement + management API.
- **`app/Http/Controllers/Hosted/*`** — the token-authorized checkout/portal pages.
- **`routes/`** — split by concern: `web.php` (console), `api.php` (enforcement +
  management), `hosted.php`, `webhooks.php`, `licensing.php`, `console.php` (schedule).
- **`config/billing.php`** — the single large config file for every billing knob.

## Related documentation

- [Quick start](../quickstart.md) — the condensed one-read version.
- [Requirements](../requirements.md)
- [Configuration](../configuration/_index.md)
