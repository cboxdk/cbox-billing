---
title: Analytics
description: The revenue and retention analytics the console surfaces — MRR movement, ARR waterfall, net revenue retention, customer churn, and cohorts — computed from real engine data.
weight: 48
---

# Analytics

Cbox Billing surfaces revenue and retention analytics from real subscription and
invoice data, not a separate warehouse. The console areas are Home → Dashboard and
Analytics → Revenue / Retention; the read models are `RevenueMetrics` and
`RevenueAnalytics` over the engine's reporting module.

## Dashboard metrics

The dashboard (`RevenueMetrics`) shows the top-line health in the account's primary
currency:

- **Revenue** (MRR/ARR) and the **plan breakdown**.
- **Churn rate**.
- **Outstanding** balance and the **open-invoice count**.

## Revenue analytics

`RevenueAnalytics` computes, for a period:

- **MRR movement** — the decomposition into new / expansion / contraction / churn.
  It is recorded incrementally by `SubscriptionMrrMovementRecorder` into
  `subscription_mrr_movements` as subscriptions change, so the movement report reads
  committed facts rather than recomputing history.
- **ARR waterfall** — annualized recurring revenue bridged across the period.
- **Cohorts** — a retention matrix across a set of periods.

## Retention analytics

The Retention view surfaces:

- **Net revenue retention (NRR)** — revenue retained and expanded within a cohort.
- **Customer churn** — the rate of customers lost over the period.

## Where the math lives

The MRR/NRR/ARR/cohort computations are the engine's reporting module; the app's
read models select and present them for the console. Captured cancellation
`reason`/`feedback` (from [retention](payments-and-dunning.md)) enrich churn
analysis. For the reporting internals see
<https://github.com/cboxdk/laravel-billing/tree/main/docs> → reporting.

## Related documentation

- [Getting started → Console tour](../getting-started/console-tour.md)
- [Cookbook → Read the analytics](../cookbook/read-analytics.md)
- [Payments & dunning](payments-and-dunning.md)
