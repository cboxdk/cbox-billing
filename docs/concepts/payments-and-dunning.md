---
title: Payments & dunning
description: Gateway-agnostic charging, the two independent dunning tracks — access-gating delinquency dunning and smart-retry payment dunning — and the retention save paths.
weight: 46
---

# Payments & dunning

Payments collect on invoices; dunning is what happens when they do not. Cbox Billing
runs **two independent dunning tracks** — one gates access, one chases money — plus
retention saves. Charging is gateway-agnostic (`PaymentService` over the bound
`PaymentGateway`).

## Charging

The bound gateway (Stripe, Mollie, or the manual signed-webhook gateway — see
[Payment gateways](../configuration/payment-gateways.md)) settles invoices. The
webhook ingest is **exactly-once**: the app binds durable `ProcessedEventStore` and
`SettledPaymentStore`, so a re-delivered settlement is a safe no-op. On settlement,
the applier marks the invoice paid — and a decorator (`CheckoutActivation`) also
activates a hosted checkout's subscription when the settled webhook references one.

## Track 1 — access-gating dunning (delinquency)

`billing:dunning` (daily, 06:00) chases delinquent accounts and, ultimately,
suspends access. Suspension gates **access only** (it flips the account's standing);
it never touches credit balances or the ledger. Policy (`config/billing.php` →
`payment.dunning`):

- `max_delinquency_days` (30) — age of the oldest past-due invoice before escalation.
- `min_notice_count` (3) — reminders that must go out first; an account is never
  suspended un-warned.
- `notice_frequency_days` (7) — the cadence between reminders.
- `grace_hours` (24) — a just-missed payment is not dunned.

Restore is strict: an account is only lifted back once **all** its debt is cleared
and none is written off, so paying part of a bill never silently reopens access.

## Track 2 — smart-retry dunning (money)

When a **renewal charge fails**, the subscription moves to **PastDue** and the
invoice is retried on the gateway on a backoff, a payment-failed email going out each
attempt. `billing:retry-payments` (daily, 06:30) fires each due attempt. Policy
(`config/billing.php` → `payment.retry`):

- `schedule` `[1, 3, 5, 7]` — day-offsets from the initial failure; the entry count
  is the max retries. Each attempt is idempotent per `(invoice, attempt)`, so a daily
  cadence enacts the schedule without double-charging.
- `terminal_action` (`cancel`) — when the schedule is exhausted: `cancel` (the
  engine's forfeiture-on-transition fires) or `none` (leave PastDue for manual
  handling; the access-gating track still governs suspension).

A retry that settles recovers the subscription to **Active** and sends a receipt.

The two tracks run **independently**: smart-retry collects money; delinquency dunning
gates access. An invoice can be under retry while the account is still within its
access grace.

## Retention

`RetentionService` handles cancellation and win-back:

- **Cancel** forks into `immediate`, `period_end`, or `pause` (a save that pauses
  instead of canceling), capturing `reason` + `feedback` for churn analytics.
- **Reactivate** resumes a paused subscription, undoes a scheduled period-end cancel,
  or re-subscribes one canceled within the win-back window
  (`reactivation_window_days`, 30). A subscription in none of those states → 409.

Console retention actions live on the subscription detail page; the API exposes
`/cancel` and `/reactivate`. See [Subscriptions & lifecycle](subscriptions-and-lifecycle.md).

## Related documentation

- [Configuration → Payment gateways](../configuration/payment-gateways.md)
- [Cookbook → Set up smart-retry dunning](../cookbook/smart-retry-dunning.md)
- [Analytics](analytics.md)
- Engine payments & dunning: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
