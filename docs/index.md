---
title: Introduction
description: Cbox Billing — the self-hostable billing, metering and entitlement application built on cboxdk/laravel-billing, with a provider console, self-service portal, enforcement + management APIs, and on-prem licensing.
weight: 1
---

# Cbox Billing

Cbox Billing is the **deployable application** for usage-based billing: plans and
pricing, real-time usage metering with hard-limit enforcement, credit wallets,
tax-aware invoicing with legal numbering, smart-retry dunning, a provider console,
and a self-service customer portal — all on real engine data.

It is the app peer to the [`cboxdk/laravel-billing`](https://github.com/cboxdk/laravel-billing)
engine, exactly as [`cbox-id`](https://github.com/cboxdk/cbox-id) is the app built
on [`laravel-id`](https://github.com/cboxdk/laravel-id). Reach for **this app** when
you want to run billing without building the UI, the OIDC wiring, the hosted
checkout, and the API surface yourself; reach for the **engine package** when you
want to embed billing primitives in your own Laravel app.

- **Repository:** `cboxdk/cbox-billing` — Laravel 13, PHP 8.4+, MIT.
- **Base image:** `ghcr.io/cboxdk/cbox-billing` (open, source-available).
- **Commercial composition:** the private `cbox-billing-cloud` overlay adds five
  feature-gated plugins on top of the base image — see [Open core](open-core/_index.md).

## The mental model

Cbox Billing is a **thin application over a deep engine**. Almost every page in
this app is a small controller or service that validates, authorizes, and maps
onto an engine-backed contract. The invariants that make billing correct — the
append-only idempotent ledger, convergent reconciliation, the three-way
enforcement outcome, preview-equals-charge, credit-pool behaviour, plan-family
transitions — live in `cboxdk/laravel-billing` and are documented there. This app
documents **what the app does**; it links down to the engine for the internals.

The app is organized around a few load-bearing ideas:

| Idea | What it means here |
| --- | --- |
| **Three layers** | Enforcement (may this proceed? sub-ms), metering truth (what happened? immutable event log), and money (what is owed? the ledger) are kept separate. The invoice is computed from the event log, never from a counter. |
| **Identity is external** | Users sign in against **Cbox ID** over OIDC. One billing account maps to one identity organization, so entitlements are enforced at the org level on the hot path — not by inflating identity tokens. |
| **Deny-by-default** | No gateway secret ⇒ webhooks refuse every payload. No consume-license ⇒ commercial plugins stay locked. No CORS allow-list ⇒ no cross-origin browser call. No API token match ⇒ 401. |
| **Two API tiers** | The **enforcement** API (`reserve`/`commit`/`usage`) runs on every metered operation and gets the higher throttle; the **management** API (subscribe/change/cancel/invoices/licenses) is human-paced, mutating, and idempotency-keyed. |
| **Open core** | The base app is MIT and complete on its own. Five private plugins compose onto it purely through Laravel auto-discovery + a console-kit socket — zero edits to the app. |

## What's inside

- **Provider console** (`/`) — dashboard (MRR/ARR, churn, outstanding),
  subscriptions, invoices, catalog, customers, usage, licenses, analytics, and
  settings, behind a Cbox ID session.
- **Enforcement API** (`/api/v1`) — lease-backed `reserve` / `commit` / `usage`
  and combined-balance entitlement checks for the metered hot path.
- **Management API** (`/api/v1`) — self-service plans, subscribe, preview-and-change,
  cancel/pause/resume/reactivate, seat quantity, add-ons, usage, invoices, payment
  methods, hosted checkout / portal sessions, embedded intents, and license
  issue/renew/revoke.
- **OpenAPI contract + SDK** — the whole `/api/v1` surface is described by a hand-authored
  [OpenAPI 3.1 contract](api/openapi.md) served live at `/api/openapi.yaml`, `/api/openapi.json`,
  and a self-contained `/api/docs` reference; a typed [TypeScript SDK](api/sdk-typescript.md)
  ships under `sdks/typescript/`.
- **Hosted surfaces** (`/billing`) — token-authorized checkout and customer portal
  pages, no provider auth gate.
- **On-prem licensing** — mint signed, offline-verifiable Ed25519 licenses from a
  licensable plan; verify them with no call home.
- **Scheduled lifecycle** — reconcile, renew cycles, convert trials, issue invoices,
  chase dunning, smart-retry failed charges, reissue licenses.

## Sections

- **[Getting started](getting-started/_index.md)** — install locally (or on Herd),
  first run + seed, run the test suite, and tour the console.
- **[Configuration](configuration/_index.md)** — the full env reference, payment
  gateways, tax and seller entities, queue/cache/session, and CORS + throttling.
- **[Identity](identity/_index.md)** — Cbox ID OIDC login, the federated RBAC
  manifest, and org-level entitlements.
- **[Concepts](concepts/_index.md)** — catalog and pricing, subscription lifecycle,
  metering and enforcement, wallets, invoicing and tax, payments and dunning,
  licensing, and analytics.
- **[API](api/_index.md)** — the enforcement API, the management API, hosted
  checkout/portal, license activation, authentication, throttling, and idempotency.
- **[Outbound webhooks](webhooks/_index.md)** — the integrator event bus: register
  endpoints, subscribe to the billing event catalog, and verify signed deliveries.
- **[Data export & warehouse sinks](data-export/_index.md)** — stream any dataset to
  CSV/NDJSON, and stage partitioned files plus copy-paste `COPY`/`bq load`/DDL load
  manifests to Snowflake, BigQuery and Redshift, with incremental, cursor-driven sync.
- **[Consolidated reporting & FX](reporting/_index.md)** — multi-entity, multi-currency
  consolidated MRR/ARR normalized to one reporting currency with real ECB reference rates
  (plus operator overrides), the as-of/rounding policy, and auditable breakdowns.
- **[Open core](open-core/_index.md)** — the plugin model, capability gating, the
  five commercial plugins, and how a deployment composes them.
- **[Console UI](console-ui/_index.md)** — the reusable console UX conventions
  (confirm dialog, pagination, breadcrumbs, accessible rows, flash, responsive
  utilities) every provider-console screen follows.
- **[Testing & sandbox](testing/_index.md)** — test mode's isolated `livemode` plane,
  the fake gateway with no real charges or emails, and fast-forwardable test clocks
  for simulating renewals, trials and dunning in seconds.
- **[Deployment](deployment/_index.md)** — the base Docker image, the private cloud
  composition, the production checklist, and day-two operations.
- **[Cookbook](cookbook/_index.md)** — task-first recipes with real commands and
  endpoints.
- **[Security](security/_index.md)** — the deny-by-default posture, the documented
  seams, and how to report a vulnerability.

## Framework documentation

The billing invariants live in the engine and its sibling packages. Where a page
here needs the internal rationale, it links down to:

- **Billing engine:** <https://github.com/cboxdk/laravel-billing/tree/main/docs>
  (and its [decision records](https://github.com/cboxdk/laravel-billing/tree/main/adr)).
- **On-prem license crypto:** [`cboxdk/license`](https://github.com/cboxdk/license).
- **Tax engine:** [`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax).
- **Console socket:** [`cboxdk/laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit).
- **Identity client:** [`cboxdk/laravel-id-client`](https://github.com/cboxdk/laravel-id).

## Related documentation

- [Quick start](quickstart.md)
- [Requirements](requirements.md)
