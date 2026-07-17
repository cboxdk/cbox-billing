---
title: Set up smart-retry dunning
description: Configure the failed-renewal-charge backoff schedule and terminal action, run the retry pass, and understand how it differs from access-gating dunning.
weight: 87
---

# Set up smart-retry dunning

When a renewal charge fails, the subscription goes **PastDue** and the invoice is
retried on a backoff. This is the money-collection track, independent of the
access-gating delinquency track. Background:
[Payments & dunning](../concepts/payments-and-dunning.md).

## Configure the schedule

`config/billing.php` → `payment.retry`:

```php
'retry' => [
    'schedule' => [1, 3, 5, 7],                 // day-offsets from the first failure
    'terminal_action' => env('CBOX_BILLING_RETRY_TERMINAL_ACTION', 'cancel'),
],
```

- `schedule` — attempt N fires `schedule[N-1]` days after the initial failure; the
  entry count is the maximum number of retries. `[1, 3, 5, 7]` retries on days 1, 3,
  5, and 7.
- `terminal_action` — when the schedule is exhausted without recovery:
  - `cancel` — cancel the subscription immediately (the engine's
    forfeiture-on-transition fires).
  - `none` — leave it PastDue for manual handling (the access-gating dunning pass
    still governs suspension).

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

## Not the same as delinquency dunning

Do not confuse this with `billing:dunning` (daily, 06:00), which **gates access**
(suspends after `max_delinquency_days` and `min_notice_count`, never touching credits
or the ledger). Smart-retry **collects money**; delinquency dunning **restricts
access**. They run independently. See
[Payments & dunning](../concepts/payments-and-dunning.md).

## Related documentation

- [Concepts → Payments & dunning](../concepts/payments-and-dunning.md)
- [Deployment → Operations](../deployment/operations.md)
