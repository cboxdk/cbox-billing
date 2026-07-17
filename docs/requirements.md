---
title: Requirements
description: Runtime and dependency requirements Cbox Billing's composer.json enforces — PHP, Laravel, the cboxdk packages it composes, and the services a production deployment needs.
weight: 3
---

# Requirements

Cbox Billing installs only when your project satisfies the constraints declared in
its `composer.json`. The versions below are exactly what the dependency resolver
enforces — nothing invented.

## PHP

- **PHP `^8.4`** — PHP 8.4 or newer. The app uses `readonly` value objects, enums,
  first-class callable syntax, and constructor property promotion throughout.

No non-standard PHP extension is required beyond a normal Laravel install (the
standard `ext-*` set: `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`,
`json`, `bcmath`/`gmp` as your platform provides). On-prem licensing signs with
Ed25519 via `ext-sodium` (bundled with PHP 8.4).

## Framework

- **`laravel/framework` `^13.8`** — Laravel 13. This is the full framework (the
  app ships routes, migrations, a console, and Blade views), not just the
  Illuminate components the engine depends on.
- **`laravel/tinker` `^3.0`** — the REPL.

## cboxdk packages (the engine and its companions)

These are the first-party packages the app composes. Their **own** internals are
documented in their repositories; this app documents how it wires and uses them.

| Package | Constraint | Role |
| --- | --- | --- |
| `cboxdk/laravel-billing` | `^0.8` | The billing engine — catalog, pricing, subscriptions, metering, ledger, wallets, invoicing, reconciliation, licensing module. |
| `cboxdk/laravel-billing-stripe` | `^0.4` | The Stripe payment-gateway adapter (binds the `PaymentGateway` + webhook verifier when configured). |
| `cboxdk/laravel-console-kit` | `^0.2.1` | The provider-console socket — nav registry, feature registry, current-context, plugin slots. |
| `cboxdk/laravel-health` | `^2.0` | Liveness/readiness + gated detail health endpoints. |
| `cboxdk/laravel-id-client` | `^0.2` | The Cbox ID OIDC relying-party client + federated RBAC manifest publisher. |
| `cboxdk/laravel-telemetry` | `^1.0` | Collector-free metrics/traces/events (off by default). |
| `cboxdk/license` | `^0.1` | Ed25519 license issue/verify + the capability gate the plugins read. |

## Other direct dependencies

- **`firebase/php-jwt` `^7.1`** — verifies the OIDC `id_token` on the sign-in
  callback.
- **`setasign/fpdf` `^1.9`** — renders invoice PDFs in pure PHP (no headless
  browser, no external runtime).

## Development dependencies

`fakerphp/faker`, `larastan/larastan` (`^3.0`, PHPStan), `laravel/pail`,
`laravel/pao`, `laravel/pint` (`^1.27`), `mockery/mockery`,
`nunomaduro/collision`, and `phpunit/phpunit` (`^12.5.12`). The verification gate
(`composer qa`) runs Pint, PHPStan, PHPUnit, the license check, and `composer audit`.

## Services a production deployment needs

These are **not** enforced by Composer — they are the runtime services the app
expects once you leave local development. See the
[production checklist](deployment/production-checklist.md).

- **A relational database** — Postgres (recommended) or MySQL. Migrations use
  portable column types and run on Postgres, MySQL, and SQLite. SQLite is
  local-only.
- **Redis / Valkey** — for session, cache, and queue in production (the local
  default is `database` for all three).
- **A queue worker + scheduler** — lifecycle jobs (invoicing, notifications,
  dunning, reconcile) run on the queue; the schedule drives the cadence.
- **An SMTP / API mailer** — lifecycle notifications are queued and delivered
  through the configured mailer.
- **A Cbox ID instance** — for real OIDC sign-in and federated RBAC (optional in
  local/demo mode).
- **A payment gateway** (optional) — Stripe, or the manual signed-webhook gateway.

## What is *not* required

- **No gateway SDK to boot.** With no Stripe keys, the dependency-free manual
  gateway is the fallback.
- **No license keys to boot.** With no signing key, licensing is inert and the app
  still runs everything else.
- **No live Cbox ID to boot.** With no issuer, the app offers demo sign-in.

## Related documentation

- [Installation](getting-started/installation.md)
- [Configuration → Environment](configuration/environment.md)
- [Quick start](quickstart.md)
