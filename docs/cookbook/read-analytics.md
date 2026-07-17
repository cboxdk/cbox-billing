---
title: Read the analytics
description: Where the dashboard and analytics numbers come from — MRR movement, ARR waterfall, NRR, customer churn, and cohorts — and how MRR movement is recorded incrementally.
weight: 91
---

# Read the analytics

The console surfaces revenue and retention analytics from real subscription and
invoice data. This recipe maps each number to where it comes from. Background:
[Analytics](../concepts/analytics.md).

## The console surfaces

| Screen | Route | Shows |
| --- | --- | --- |
| Dashboard | `/` | MRR/ARR, churn rate, outstanding, open-invoice count, plan breakdown. |
| Analytics → Revenue | `/analytics/revenue` | MRR movement, ARR waterfall, cohorts. |
| Analytics → Retention | `/analytics/retention` | Net revenue retention, customer churn. |

All figures render in the account's **primary currency** (`RevenueMetrics::primaryCurrency()`).

## How MRR movement is computed

MRR movement (new / expansion / contraction / churn) is **recorded incrementally**:
as subscriptions change, `SubscriptionMrrMovementRecorder` writes movement rows into
`subscription_mrr_movements`. The movement report (`RevenueAnalytics::movement()`)
then reads committed facts for a period rather than recomputing history from scratch —
so the numbers are stable and auditable.

## What each number means

- **MRR / ARR** — recurring revenue, monthly and annualized. The plan breakdown
  splits MRR by plan.
- **ARR waterfall** — the annualized bridge across a period (`arr()`).
- **NRR (net revenue retention)** — revenue retained + expanded within a cohort
  (`retention()`), independent of new logos.
- **Customer churn** — the rate of customers lost over the period (`customerChurn()`);
  the dashboard's `churnRate()` is the top-line figure.
- **Cohorts** — a retention matrix across a set of periods (`cohorts()`), with
  `monthLabels()` for the axis.
- **Outstanding / open invoices** — unpaid balance and count (`outstanding()`,
  `openInvoiceCount()`).

Captured cancellation reasons/feedback (from [retention](../concepts/payments-and-dunning.md))
enrich churn analysis.

## Where the math lives

The MRR/NRR/ARR/cohort computations are the engine's reporting module; the app's
`RevenueMetrics` / `RevenueAnalytics` read models select and present them. For the
reporting internals see
<https://github.com/cboxdk/laravel-billing/tree/main/docs> → reporting.

## Related documentation

- [Concepts → Analytics](../concepts/analytics.md)
- [Getting started → Console tour](../getting-started/console-tour.md)
