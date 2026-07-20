---
title: Deterministic assignment
description: How a visitor is stickily and reproducibly bucketed into a variant by a hashed weighted function — sticky across page views, reproducible in tests, weighted in proportion to the variant weights, with no random seed to lose.
weight: 20
---

# Deterministic assignment

A visitor is assigned to a variant by a **pure function of `(visitor id, experiment key)`** — no
`rand()`, no time, no database state:

```
point   = hash(visitorId ':' experimentKey)  mod  totalWeight
variant = the arm whose cumulative-weight window contains `point`
```

The hash is SHA-256; its top 60 bits are taken as an integer (well within PHP's 64-bit signed
range, so the modulo is exact), which spreads visitor ids uniformly across the weight space.
Variants are walked in a stable order — control first, then `sort_order`, then id — so the
cumulative windows are identical on every call.

This gives three properties at once:

- **Sticky** — the same visitor always lands on the same variant, so a returning visitor sees a
  consistent price and never flaps between refreshes.
- **Reproducible & testable** — a fixed set of visitor ids maps to a fixed, assertable split.
  There is no seed to store or lose: re-running the assignment over the same ids yields the same
  buckets, so a test can assert both "this id always maps here" and "10,000 ids split ≈ to the
  weights within tolerance".
- **Weighted** — an arm with twice the weight receives ≈ twice the traffic.

## Worked example

With a control weight of `1` and a challenger weight of `3` (`totalWeight = 4`), a visitor whose
hash mod 4 is `0` lands on the control and `1–3` on the challenger — a 25% / 75% split. Feeding a
fixed batch of 10,000 synthetic ids through the assigner reproduces that split to within a couple
of percent every time.

## The visitor id

The `visitorId` is an **anonymous, random cookie** — see [attribution](attribution.md) for its
privacy properties. It is the only per-visitor input to assignment; everything else is the
experiment's static configuration.

The assigner is `App\Billing\Experiments\VariantAssigner`. Its `bucket()` method is public so a
test — or an operator debugging why a visitor saw a particular price — can reproduce the exact
bucket independent of the variant set.
