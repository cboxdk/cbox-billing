---
title: Console tour
description: A guided tour of every area of the provider console — dashboard, analytics, subscriptions, invoices, usage, catalog, customers, licenses, and settings.
weight: 14
---

# Console tour

The provider console is served at `/` behind a Cbox ID session (`auth.cbox`
middleware). Its navigation is seeded from a single source of truth — the
`App\Platform\ConsoleNav` IA — into the shared console-kit nav registry, so an
installed [commercial plugin](../open-core/_index.md) can add areas or pages with
no edit to the app. Live counts are overlaid onto the nav by a view composer.

## The areas

| Area | Route(s) | What it shows |
| --- | --- | --- |
| **Home → Dashboard** | `/` | MRR/ARR, churn, outstanding balance, open-invoice count, and a plan breakdown — the top-line health, computed by the engine's reporting module. |
| **Analytics → Revenue** | `/analytics/revenue` | MRR movement (new/expansion/contraction/churn), ARR waterfall, and cohorts. |
| **Analytics → Retention** | `/analytics/retention` | Net revenue retention and customer churn. |
| **Subscriptions** | `/subscriptions` | All subscriptions, filterable by status: Active, Trials, Past due, Paused, Non-renewing, Canceled. A per-subscription detail page and a **Dunning** view. |
| **Invoices** | `/invoices` | All / Open / Paid / Drafts, an invoice detail page, and a PDF download (`/invoices/{id}/pdf`, rendered with FPDF). |
| **Usage** | `/usage` | Metered usage per meter, from the reconciled ledger. |
| **Catalog → Products** | `/catalog` | The product catalog. |
| **Catalog → Plans & pricing** | `/pricing` | Plans, prices per currency, and tier tables for tiered pricing models. |
| **Customers** | `/customers` | Billing organizations and their entitlements; a per-org detail page. |
| **Licenses** | `/licenses` | Issued on-prem licenses (**Issued**) and the public-key **Distribution** panel. Gated on the `licenses` console-kit feature. |
| **Settings** | `/settings` | Seller entities, tax, payment gateways, API tokens, and webhooks. |

## Retention actions

The subscription detail page exposes retention actions backed by the app's
`ManagesRetention` service: cancel-with-reason, pause, and reactivate. Captured
churn reasons feed the analytics. See [Payments & dunning](../concepts/payments-and-dunning.md)
and [Subscriptions & lifecycle](../concepts/subscriptions-and-lifecycle.md).

## Feature gating vs entitlement

Two different gates shape what a console shows, and the app never conflates them:

- **Feature (presence) gate** — a console-kit `feature` is a hard on/off. When off,
  the page is hidden and its routes 404. The base app registers `licenses` as
  always-on; a stripped deployment can turn it off and the whole Licenses area
  disappears.
- **Entitlement (upgrade) soft-lock** — the page renders, but an action is blocked
  when the plan does not entitle it, carrying the path to unlock. This is the
  `UpgradeGate` bridge described in [Metering & enforcement](../concepts/metering-and-enforcement.md).

Commercial plugins add their own areas (Reseller, Revenue recognition, Connectors,
Tax filing) through the same socket — see [Open core](../open-core/_index.md).

## Related documentation

- [Identity → OIDC login](../identity/oidc-login.md)
- [Open core → The plugin model](../open-core/plugin-model.md)
- [Concepts → Analytics](../concepts/analytics.md)
