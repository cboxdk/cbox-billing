# Cbox Billing

The self-hostable billing, metering and entitlement platform — the deployable app
built on [`cboxdk/laravel-billing`](https://github.com/cboxdk/laravel-billing).
Plans and pricing, real-time usage metering with hard-limit enforcement, credit
wallets, tax-aware invoicing with legal numbering, dunning, and a self-service
customer portal — with a provider console on top.

Repo: `cboxdk/cbox-billing` (private). This app composes the framework package and
adds the provider console + hosted-cloud concerns (UI, onboarding, payment-gateway
wiring, the hosted checkout/portal, and the customer-facing self-service surface).

## Stack

- **Laravel 13**, PHP 8.4+.
- Depends on **`cboxdk/laravel-billing`** (the engine) and
  **`cboxdk/laravel-billing-stripe`** (a payment-gateway adapter) from
  Composer/Packagist. Mollie is available as `cboxdk/laravel-billing-mollie`; a
  manual, operator-reconciled gateway ships in the engine.
- Signs in against **Cbox ID** over OIDC — one billing account per identity
  organization, so entitlements are enforced at the org level on the application
  hot path (not by inflating identity tokens).
- Invoice PDFs render with **`setasign/fpdf`** (MIT) — pure PHP, no headless
  browser, no external runtime.

## What's inside

- **Provider console** — dashboard (MRR/ARR, churn, outstanding), subscriptions,
  invoices, catalog, customers, usage, and settings, all on real engine data.
- **Enforcement API** (`/api/v1`) — lease-backed `reserve` / `commit` / `release`
  and combined-balance entitlement checks for the metered hot path.
- **Management API** — self-service plans, subscribe, preview-and-change (preview
  equals charge), cancel, usage, invoices, plus hosted checkout / customer-portal
  sessions and embedded payment / setup intents (both integration paths).
- **Scheduled jobs** — cycle renewal (per-cadence allotment grants + period
  advance), convergent reconciliation, dunning, and invoice issuance.

## Quickstart

```bash
git clone … && cd cbox-billing
composer setup          # installs deps, copies .env, generates the app key,
                        # runs migrations, builds front-end assets
composer run dev        # serve + queue + vite + logs
```

Seed the catalog and a first organization with `php artisan migrate:fresh --seed`.
The provider console is served at `/`; the metered hot path and self-service
surface live under `/api/v1`.

Required env lives in `.env.example` (keep secrets out of git): the Cbox ID OIDC
issuer/client credentials, and — per gateway you enable — the gateway API and
webhook-signing secrets. The manual gateway needs no external credentials.

## Verification

```bash
composer qa             # pint --test · phpstan (level max) · pest · license-check · audit
composer sbom           # regenerate the CycloneDX SBOM (CI fails on drift)
```

All production dependencies are permissively licensed
(`composer license-check` enforces MIT/BSD/Apache/ISC/0BSD).

## Framework-level detail

The billing invariants — credit-pool behaviour matrix, ledger idempotency,
convergent reconciliation, the three-way enforcement outcome, preview-equals-charge,
plan families and transition policy, unified entitlement and allotment distribution —
live in the engine and are documented there, including the architecture decision
records:

- Engine docs: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
- Decision records: <https://github.com/cboxdk/laravel-billing/tree/main/adr>

## License

MIT — see [LICENSE](LICENSE).
