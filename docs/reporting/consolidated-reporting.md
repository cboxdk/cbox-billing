---
title: Consolidated MRR/ARR
description: The consolidated read models and their formulae, the reporting-currency selector and entity filter, the consolidated movement bridge with NRR/GRR, and the auditable per-currency and per-entity breakdowns.
weight: 20
---

# Consolidated MRR/ARR

`ConsolidatedRevenueReport` normalizes the whole book to one **reporting currency** with real FX,
additive on top of the per-currency read models (which stay untouched).

## Formulae

All amounts are exact integer minor units; every rate comes from the `fx_rates` store (see
[FX rates](fx-rates.md)), never fabricated.

```
consolidated MRR = Σ over currencies( native MRR in that currency → reporting currency at the effective rate )
consolidated ARR = consolidated MRR × 12
```

**Conversion policy.** Each currency's *aggregated* native MRR is converted **once** at that
currency's effective rate (one rate per currency, applied to the net exposure), and the results are
summed. So the consolidated total equals the sum of the per-currency converted lines exactly, and
the per-currency table is the audit unit. A currency with no resolvable rate is listed as
**unavailable** and excluded from the sum — never converted at an assumed rate.

**As-of policy.** The MRR headline uses the live ("now") rate. A movement bridge uses the
documented `billing.reporting.fx.as_of` basis — `period_end` by default (a closed period's rate
never moves, so the consolidation is reproducible) or `today` (spot). Every per-currency line shows
the exact date of the rate row applied, so the number is auditable, never a black box.

## Reporting currency

`config('billing.reporting.currency')` sets the default; when null, the default selling entity's own
currency is used (so a single-entity deployment needs no config). The console's reporting-currency
selector overrides it per request (`?reporting=`).

```php
'reporting' => [
    'currency' => env('CBOX_BILLING_REPORTING_CURRENCY'), // null → default seller currency
    'fx' => ['as_of' => env('CBOX_BILLING_REPORTING_FX_ASOF', 'period_end')],
],
```

## Breakdowns

- **Per-currency** (`byCurrency`) — for each native currency: the native MRR, subscription count,
  the converted amount, and the exact `EffectiveRate` (its decimal value, provenance ECB/override,
  and as-of date).
- **Per-entity** (`byEntity`) — the same rolled up per **selling entity**. A subscription is
  attributed to the entity of record that bills it: the `seller` on its invoices (an un-invoiced
  subscription falls back to the default selling entity). An entity billing in several currencies
  shows each, and its consolidated total is flagged `partial` if any of its currencies had no rate.

## Consolidated movement & retention

`ConsolidatedRevenueReport::movement(...)` folds every currency's native MRR-movement waterfall into
one reporting-currency bridge: each component (new / expansion / contraction / churn / reactivation)
is converted at that currency's period-end rate and summed. The consolidated **ending MRR is the
accounting identity** over the converted components, so the bridge reconciles exactly despite
per-component rounding. Consolidated **NRR/GRR** are computed from that bridge by the engine's
`RetentionCalculator`. Currencies with no period-end rate are excluded and named.

The movement bridge is a book-wide consolidation; the entity filter scopes the MRR/ARR
breakdown tables.

## Console

**Analytics → Revenue** gains a reporting-currency selector, an entity filter (all / one entity),
the consolidated MRR/ARR headline, the auditable per-currency table (native → converted with the
rate + as-of date), the per-entity roll-up, and the consolidated movement bridge with NRR/GRR. The
per-currency detail below it is the original, unchanged view.
