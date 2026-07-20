---
title: Significance
description: The two-proportion z-test used as the significance signal — the exact formula, how the confidence is derived, and an honest account of what the signal does and does NOT license (peeking, small samples, fixed-horizon assumptions).
weight: 40
---

# Significance

The results dashboard shows, for each challenger, a **significance signal**: is its conversion
rate meaningfully different from the control's, or is the gap just sampling noise?

## The statistic

It is a **pooled two-proportion z-test**. For a challenger `(c₁ conversions, n₁ impressions)`
against the control `(c₀, n₀)`:

```
p̂  = (c₁ + c₀) / (n₁ + n₀)                     pooled rate under H₀: the rates are equal
SE = sqrt( p̂ (1 − p̂) (1/n₁ + 1/n₀) )            standard error of the difference
z  = (c₁/n₁ − c₀/n₀) / SE
```

The two-sided p-value is `2·(1 − Φ(|z|))`, where Φ is the standard-normal CDF (computed from
`erf` via the Abramowitz & Stegun 7.1.26 approximation, max error ≈ 1.5e-7). **Confidence** is
`1 − p`; the console calls a challenger "significant" at **95% confidence** (α = 0.05).

Everything is pure arithmetic on the counts, so the numbers are deterministic and exactly
reproducible — a seeded `20/100` vs `10/100` always yields `z ≈ 1.980`, confidence ≈ 95.2%.

It lives in `App\Billing\Experiments\Statistics\TwoProportionZTest`.

## Honest caveats — read this

The significance signal is a **guide, not a verdict**. Specifically:

- **Don't peek.** The z-test's p-value is only valid if you fix the sample size in advance and
  read it **once**. Watching the dashboard and stopping the moment a variant crosses 95%
  ("optional stopping") inflates the real false-positive rate well beyond 5%. If you must monitor
  continuously, decide a horizon up front and hold to it.
- **Small samples lie.** The normal approximation to the binomial needs a reasonable number of
  conversions in each arm. With only a handful, the number is noise — the console shows the
  confidence but you should not act on it.
- **It assumes independence.** One row per anonymous visitor, visitors independent of one
  another. Bot traffic, a single buyer across many devices, or a campaign spike can all violate
  that.
- **Significant ≠ meaningful.** A statistically significant 0.1% lift may not be worth shipping;
  weigh the effect size and the operational cost.

Treat a green signal as **"worth a closer look"**, not "ship it". The console never auto-promotes
a winner for exactly this reason — [promotion](concluding.md) is always an explicit, human
decision.
