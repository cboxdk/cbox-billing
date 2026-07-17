---
title: Installation
description: Install Cbox Billing from a clean checkout — the composer setup/dev/test scripts, Laravel Herd, and choosing SQLite for local dev vs Postgres/MySQL for production.
weight: 11
---

# Installation

Cbox Billing builds from a clean checkout with a plain `composer install` — every
production dependency is public on Packagist, and the committed `composer.lock`
resolves them to their published releases.

## Prerequisites

- **PHP 8.4+**, **Composer 2**, **Node 18+** with npm. See [Requirements](../requirements.md).

## Clone and set up

```bash
git clone https://github.com/cboxdk/cbox-billing.git
cd cbox-billing
composer setup
```

The `setup` script (defined in `composer.json`) runs the full first-time sequence:

1. `composer install` — install PHP dependencies.
2. Copy `.env.example` → `.env` (only if `.env` does not already exist).
3. `php artisan key:generate` — generate `APP_KEY`.
4. `php artisan migrate --force` — run migrations.
5. `npm install --ignore-scripts` then `npm run build` — build front-end assets.

> The shipped `.env.example` teaches **production-safe defaults** (`APP_ENV=production`,
> `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, `LOG_CHANNEL=json`). For local work,
> override the three below.

## Choosing a database

### Local: SQLite (zero-config)

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
```

`DB_DATABASE` then defaults to `database/database.sqlite`. The
`post-create-project-cmd` scripts `touch` that file for you; if you cloned rather
than created-project, create it once with `touch database/database.sqlite`.

### Production: Postgres or MySQL

The migrations use portable column types (`json()`, standard integers/strings) and
run on Postgres, MySQL, and SQLite alike. Postgres is recommended:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cbox_billing
DB_USERNAME=cbox_billing
DB_PASSWORD=
```

Do **not** run SQLite in production. See [Configuration → Environment](../configuration/environment.md).

## Running the app

The `dev` script runs everything you need concurrently:

```bash
composer run dev
```

It starts, with colored, interleaved output:

- `php artisan serve` — the HTTP server (default <http://localhost:8000>).
- `php artisan queue:listen --tries=1` — the queue worker (lifecycle jobs).
- `php artisan pail` — live log tail.
- `npm run dev` — the Vite dev server (hot asset reload).

Stop them all with a single Ctrl-C (`--kill-others`).

## Laravel Herd

Cbox Billing is a stock Laravel 13 app, so [Herd](https://herd.laravel.com) works
without special setup:

1. Park or link the project directory in Herd; it is served at
   `https://cbox-billing.test` (or your linked host).
2. Set `APP_URL` to that host in `.env`.
3. Herd runs PHP-FPM, so you do not need `php artisan serve`. Still run the queue
   worker and Vite when you need them: `php artisan queue:listen` and `npm run dev`.
4. SQLite works out of the box; for Postgres/MySQL use Herd's bundled services or
   your own.

## The composer scripts

| Script | What it does |
| --- | --- |
| `composer setup` | First-time install (deps, env, key, migrate, assets). |
| `composer run dev` | Serve + queue + logs + Vite, concurrently. |
| `composer test` | `config:clear` then `php artisan test`. |
| `composer lint` | `pint --test` (code-style check, no changes). |
| `composer analyse` | PHPStan (`phpstan analyse`, level from `phpstan.neon`). |
| `composer qa` | `lint` + `analyse` + `test` + `license-check` + `composer audit`. |
| `composer sbom` | Regenerate the CycloneDX SBOM (CI fails on drift). |
| `composer deploy` | The production deploy step — see [Deployment](../deployment/_index.md). |

## Related documentation

- [First run & seed data](first-run.md)
- [Running the tests](testing.md)
- [Configuration → Environment](../configuration/environment.md)
