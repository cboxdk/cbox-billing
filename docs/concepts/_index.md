---
title: Concepts
description: The billing concepts Cbox Billing exposes — catalog and pricing, subscription lifecycle, metering and enforcement, wallets, invoicing and tax, payments and dunning, licensing, and analytics — each linking down to the engine for internals.
weight: 40
---

# Concepts

This section explains the billing concepts **as the app exposes them**: the models
it stores, the services it binds, the console screens and API endpoints they back,
and the scheduled jobs that drive them.

Because Cbox Billing is a thin application over the `cboxdk/laravel-billing` engine,
each page keeps the engine-internal deep dive brief and **links down** to the engine
docs and decision records for the invariants. The rule is a clean docs boundary:
**the app docs claim the app's capabilities; the framework packages document their
own internals.**

## In this section

| Page | What the app provides |
| --- | --- |
| [Catalog & pricing](catalog-and-pricing.md) | Products, plans, prices per currency, and the six pricing models (flat, per-unit, graduated, volume, package, stairstep). |
| [Subscriptions & lifecycle](subscriptions-and-lifecycle.md) | Trials, the state machine, ramps, minimum commitments, pause/resume, quantity, add-ons. |
| [Seats](seats.md) | Purchased Full seats (the billing driver) vs assignment; Full/Light; the auto-assign toggle; Light is free. |
| [Metering & enforcement](metering-and-enforcement.md) | The reserve/commit hot path, leases, aggregations, hard limits, the upgrade bridge. |
| [Wallets & credits](wallets-and-credits.md) | The unified credit pool, cadence grants, and how included allowances are sourced. |
| [Invoicing & tax](invoicing-and-tax.md) | Per-seller invoice numbering, PDF rendering, credit notes, and tax composition. |
| [Payments & dunning](payments-and-dunning.md) | Charging, access-gating dunning, and smart-retry dunning + retention. |
| [Licensing](licensing.md) | Issuing signed, offline-verifiable on-prem licenses; renew and revoke. |
| [Analytics](analytics.md) | MRR movement, ARR waterfall, NRR, customer churn, cohorts. |

## The three layers (recap)

The load-bearing separation the whole app is built around:

| Layer | Question | Store | Authority |
| --- | --- | --- | --- |
| Enforcement | May this proceed? (sub-ms) | App-local cache counter, leased slice | none — bounded, backfillable drift |
| Metering truth | What happened? | Immutable append-only event log | metering source of truth |
| Money | What is owed/paid/owned? | Double-entry ledger | money source of truth |

The invoice is **computed from the event log**, never read from a counter; the
ledger is trued up from the event log by [reconciliation](metering-and-enforcement.md).
Full rationale: <https://github.com/cboxdk/laravel-billing/tree/main/docs>.

## Related documentation

- [API](../api/_index.md)
- [Cookbook](../cookbook/_index.md)
- Engine decision records: <https://github.com/cboxdk/laravel-billing/tree/main/adr>
