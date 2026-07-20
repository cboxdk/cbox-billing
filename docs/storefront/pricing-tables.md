---
title: Authoring a pricing table
description: Create and configure an embeddable pricing table in the console — the plan columns, the featured column, the feature-comparison matrix, currencies, the monthly/yearly interval toggle, branding, the CTA, and the live preview.
weight: 10
---

# Authoring a pricing table

A pricing table is authored in the console under **Catalog → Pricing tables** (gated by
`catalog:manage` for writes, `catalog:read` for reads). It is a pure projection over the
catalog — it grants nothing and no subscription depends on it — so it is safe to delete
outright; the `active` flag takes one offline without deleting.

## What a table carries

- **Public slug (`key`)** — addresses `/pricing/{key}`. An inactive or unknown key 404s.
- **Plan columns** — the ordered set of plans to show. Each column names the (monthly) plan, its
  display order, whether it is the **featured** column (visually lifted), an optional **badge**
  ("Most popular") and a one-line **highlight**. A column may also name an **annual plan** — its
  yearly-priced sibling — which the monthly/yearly toggle switches the column's price to.
- **Feature comparison matrix** — the ordered set of catalog features to compare across the
  columns. Each cell is read from that column plan's feature grant: ✓ for a granted boolean
  feature, the typed value for a config feature (e.g. `max_projects = 10`), or — when not granted.
- **Currencies** — which currencies the table may present. Leave unset to present every currency
  the columns' plans are priced in (deny-by-default — never a currency no plan carries). The
  default currency is the one selected on first render.
- **Interval toggle** — show the monthly/yearly switch. It only appears when at least one column
  actually carries an annual plan.
- **Branding** — the selling entity whose brand (logo, accent colour, legal name) the page wraps
  around. Unset falls back to the default seller / app-level branding. See [Branding](branding.md).
- **CTA** — the button label and its deep-link target. See
  [the checkout deep-link contract](checkout-deep-link.md).

## Prices, allowances and the matrix are catalog truth

Nothing about a table restates a price. Every amount is the stored per-currency minor amount,
formatted through the same `MoneyFormatter` the console and invoices use; each column's
allowance bullets are its plan's enabled metered entitlements; each matrix cell is the plan's
feature grant. Change a price or a grant in the catalog and every table that projects it updates.

## Live preview

The table detail page renders the **actual** public table in an iframe (the real
`/pricing/{key}/embed` view) alongside the copy-paste [embed snippet](embedding.md), so what you
author is exactly what a marketing site ships. The preview renders even while the table is
offline.
