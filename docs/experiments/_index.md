---
title: A/B pricing experiments
description: Run controlled pricing experiments on the public storefront and measure conversion by variant — the experiment model, deterministic sticky assignment, impression and conversion attribution, the two-proportion-z significance signal (with its honest caveats), and concluding by promoting a winner.
weight: 67
---

# A/B pricing experiments

Pricing experiments run a **controlled A/B test on a public pricing table** and measure
conversion by variant. You point an experiment at a `/pricing/{key}` page, define two or more
variants (each serving a pricing table, weighted for traffic), start it, and watch per-variant
impressions, conversions, conversion rate, lift, and a significance signal accrue — then
conclude by promoting a winner, which repoints the public page at the winning table.

Experiments sit directly on top of the [storefront](../storefront/_index.md): a variant *is* a
pricing table, so what a bucketed visitor sees is exactly what that table renders, and a
conversion is attributed through the same [checkout deep-link](../storefront/checkout-deep-link.md)
the storefront already uses. An experiment owns no money and grants nothing — it is a projection
and a measurement layer over catalog-backed pricing tables.

## The mental model

```
/pricing/{key}  ──▶  running experiment on this table?
                        │ yes                         │ no
                        ▼                             ▼
             assign visitor → variant          concluded + promoted winner?
             (deterministic, sticky)             │ yes            │ no
                        │                         ▼               ▼
             serve variant's table         serve winner's     serve the
             + record impression           table (canonical)  base table
             + thread attribution
             onto the CTA links
```

Only a **running** experiment does per-visitor assignment and accrues impressions/conversions. A
**draft** experiment leaves its page untouched; a **concluded** experiment either reverts to the
base table or — if a winner was promoted — serves the winner's table as the new canonical page.

## Sections

- **[The experiment model](model.md)** — experiments, variants, the required control, traffic
  weights, and the primary metric.
- **[Deterministic assignment](assignment.md)** — how a visitor is stickily and reproducibly
  bucketed into a variant, and why the split is testable with no seed.
- **[Conversion attribution](attribution.md)** — the anonymous visitor id, impressions, and how a
  checkout start + settlement is attributed back to a variant idempotently.
- **[Significance](significance.md)** — the two-proportion z-test, what it tells you, and the
  honest caveats about what it does **not** license.
- **[Concluding & promoting a winner](concluding.md)** — stopping a test and repointing the
  public page at the winning variant.

## What it does not do

- It does not fabricate a "winner" — the significance signal is a **guide**, and the console
  never auto-ships. Promotion is always an explicit operator action.
- It does not track individuals — the visitor id is an anonymous, random cookie, never a customer
  identifier (see [attribution](attribution.md)).
- It is not a sequential-testing engine — the significance statistic is a fixed-horizon z-test;
  read the [caveats](significance.md).
