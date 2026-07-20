---
title: Concluding & promoting a winner
description: Stopping a running experiment, optionally promoting a winning variant so the public pricing page serves it permanently, and how promotion is a non-destructive, reversible pointer rather than a mutation of any pricing table.
weight: 50
---

# Concluding & promoting a winner

## Concluding

Concluding a running experiment stamps it `concluded`, records `concluded_at`, and **stops
variant assignment immediately** — the next visitor is no longer bucketed. You can conclude:

- **with no winner** — the public `/pricing/{key}` page reverts to its plain base table, or
- **with a winner** — you promote one variant, and the page serves that variant's table.

The console pre-selects the **leader** (the significant challenger with the highest conversion
rate) in the conclude form, but you can promote any variant, or none. Nothing auto-ships — read
the [significance caveats](significance.md) before you promote on a green signal.

## How promotion serves the winner

Promotion sets `promoted_variant_id` on the experiment. Serving resolution then does the
following for `/pricing/{key}`:

1. a **running** experiment on the table → assign + serve the assigned variant;
2. else a **concluded** experiment with a promoted winner → serve the **winner's table** as the
   new canonical page (no assignment, no impressions — the test is over);
3. else → serve the plain base table.

So promotion **repoints the public page at the winning table** without touching any pricing table:
it is a stored pointer, not a mutation or a move. That makes it **non-destructive and reversible** —
you can promote a different variant, or clear the promotion, and no pricing table's own definition,
key, or content ever changes. The losing variants' tables are left exactly as they were.

## Draft and concluded experiments do not A/B

Only a **running** experiment does per-visitor assignment. A draft experiment serves the base
table (no assignment, no impressions); a concluded experiment either reverts to the base table or
serves the single promoted winner to everyone. Neither splits traffic — so "a draft or concluded
experiment doesn't serve variants" in the A/B sense.

The lifecycle is `App\Billing\Experiments\ExperimentLifecycle`; serving resolution is
`App\Billing\Experiments\StorefrontExperimentResolver`.
