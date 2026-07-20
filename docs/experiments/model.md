---
title: The experiment model
description: The Experiment, its variants, the required control, relative traffic weights, the primary metric it optimises for, and the pricing table it runs on.
weight: 10
---

# The experiment model

An **experiment** is defined by:

- a public `key` and a `name`,
- an optional `hypothesis` (what you expect and why),
- the **pricing table it runs on** (`pricing_table_id`) — the table whose `/pricing/{key}` page
  the experiment intercepts while running,
- a **primary metric** (`checkout_started` or `checkout_completed`),
- a lifecycle `status` (`draft` → `running` → `concluded`),
- and its **variants**.

## Variants

Each variant carries a `label`, an `is_control` flag, a relative integer traffic `weight`, and
the pricing table it serves (`served_pricing_table_id`). A **null served table means "serve the
experiment's base table"** — the natural default for the control, which shows the unchanged page.

Rules, enforced deny-by-default when you create/start an experiment:

- **exactly one control is required** (the baseline lift is measured against it),
- **at least one challenger** must exist alongside it,
- **weights are non-negative integers and must sum to more than zero** — the weights are
  *relative*, not percentages, so `1 : 3` and `25 : 75` mean the same 25% / 75% split.

A variant owns no pricing of its own; it points at a real, catalog-backed pricing table, so what
a bucketed visitor sees is exactly what that table renders.

## The primary metric

An experiment optimises for **one** metric, and the results count the conversions of exactly that
kind:

| Metric | Counts | Signal |
| --- | --- | --- |
| `checkout_started` | A hosted checkout session was minted carrying the variant's attribution. | Top-of-funnel intent. |
| `checkout_completed` | The checkout settled into a subscription (the gateway's settled webhook). | Bottom-of-funnel revenue. |

Both kinds are always recorded as they happen — a start, then a completion — so you can change
which one you report on without losing data; the primary metric only decides which one the
[results and significance](significance.md) are computed over.

## Lifecycle

- **draft** — still being configured; the page serves its plain base table.
- **running** — assigning visitors and accruing impressions/conversions.
- **concluded** — the test is over; optionally a winner is promoted (see
  [concluding](concluding.md)).

The model lives in `App\Models\Experiment` / `ExperimentVariant`; authoring and lifecycle are
`App\Billing\Experiments\ExperimentAuthoring` and `ExperimentLifecycle`.
