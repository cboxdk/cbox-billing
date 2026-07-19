---
title: Set up adaptive smart-retry dunning
description: Configure the decline-code-aware recovery strategy — per-category backoff curves and timing heuristics — tune it from the console, wire the card-updater webhook, and read the recovery analytics.
weight: 87
---

# Set up adaptive smart-retry dunning

When a renewal charge fails, the subscription goes **PastDue** and the invoice is
retried — but *how* depends on **why** it declined. Adaptive dunning classifies the
gateway decline code into a recovery category and retries on a per-category curve. This is
the money-collection track, independent of the access-gating delinquency track. Concept
background: [Payments & dunning](../concepts/payments-and-dunning.md).

## The base schedule

`config/billing.php` → `payment.retry` is the **base curve** every un-tuned category
inherits (so an untuned deployment behaves exactly as before):

```php
'retry' => [
    'schedule' => [1, 3, 5, 7],                 // day-offsets from the first failure
    'terminal_action' => env('CBOX_BILLING_RETRY_TERMINAL_ACTION', 'cancel'),
],
```

- `schedule` — the base backoff; attempt N fires `schedule[N-1]` days after the first
  failure. Its entry count is the retry ceiling unless a category overrides it.
- `terminal_action` — when the schedule is exhausted without recovery:
  - `cancel` — cancel the subscription immediately (the engine's
    forfeiture-on-transition fires).
  - `none` — leave it PastDue for manual handling (the access-gating dunning pass
    still governs suspension). A later card-update can then re-open recovery.

## Tune the per-category strategy

`config/billing.php` → `dunning.strategies` shapes the recovery per decline category:

```php
'dunning' => [
    'strategies' => [
        'max_window_days' => 30,        // never retry beyond this window
        'payday_days'     => [1, 15],   // insufficient-funds is pulled to these anchors
        'quiet_weekdays'  => [6, 7],    // pushed off Sat/Sun when a category avoids weekends
        'categories' => [
            'hard'               => ['retry' => false],
            'insufficient_funds' => ['backoff_days' => [2, 5, 9, 14], 'avoid_weekends' => true, 'align_to_payday' => true],
            'try_again_later'    => ['backoff_days' => [2, 5, 10, 16, 24], 'avoid_weekends' => true],
            'needs_action'       => ['backoff_days' => [1, 3, 5]],
            // `recoverable` and `unknown` inherit the base curve.
        ],
    ],
],
```

Or tune it **at runtime** from the console — **Settings → Retry strategy** (gated
`settings:manage`): edit a category's curve/heuristics and it persists to
`dunning_strategies` and is read live by the strategy (no redeploy). A hard category can
never be made retryable. "Revert to defaults" drops the override.

## Wire the card / account-updater

A hard decline (lost/expired/closed card) stops the retries. When a fresh card lands, a
verified card-update webhook re-opens recovery and re-attempts the account's in-dunning
charges immediately:

```
POST /webhooks/{gateway}/payment-method
```

- **Stripe**: set `STRIPE_WEBHOOK_SECRET` and point Stripe's
  `payment_method.automatically_updated` (the automatic account updater — a live Stripe
  feature you must enable on the account), `payment_method.updated`, and
  `customer.source.updated` events here.
- **Manual / any adapter**: set `CBOX_BILLING_WEBHOOK_SECRET` and POST an HMAC-signed body
  `{event_id, type: "payment_method.automatically_updated", account, payment_method_id,
  brand?, last4?, exp_month?, exp_year?}`.

Deny-by-default: with no signing secret the endpoint refuses every payload.

## Run it

The scheduler fires it daily:

```
Schedule::command('billing:retry-payments')->dailyAt('06:30')
```

Run it manually while testing:

```bash
php artisan billing:retry-payments
```

Each attempt is idempotent per `(invoice, attempt)` and only fires when its offset has
come due, so a daily cadence enacts the schedule without ever double-charging. A
payment-failed email goes out each attempt.

## Outcomes

- A retry that **settles** recovers the subscription to **Active** and sends a
  receipt.
- An **exhausted** schedule runs the `terminal_action`.
- A **hard** decline never retries — it runs the terminal action up front and emails the
  customer to add a new method (plus surfaces a retention save-offer).
- Every step appends to the per-subscription **attempts timeline** and sends a
  decline-category-tailored email.

## Read the recovery analytics

The **Dunning** screen and a **dashboard card** surface the payoff over real retry rows:
recovery rate (recovered ÷ entered-dunning), recovery **by decline category**, average
attempts-to-recover, revenue recovered, and involuntary-churn averted. The per-subscription
detail page shows the decline code + category, the adaptive next-attempt (and why), and the
full attempts timeline.

## Not the same as delinquency dunning

Do not confuse this with `billing:dunning` (daily, 06:00), which **gates access**
(suspends after `max_delinquency_days` and `min_notice_count`, never touching credits
or the ledger). Smart-retry **collects money**; delinquency dunning **restricts
access**. They run independently. See
[Payments & dunning](../concepts/payments-and-dunning.md).

## Related documentation

- [Concepts → Payments & dunning](../concepts/payments-and-dunning.md)
- [Deployment → Operations](../deployment/operations.md)
