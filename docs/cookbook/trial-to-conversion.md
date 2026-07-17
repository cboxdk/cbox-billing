---
title: Run a trial to conversion
description: Open a subscription with a free trial, understand the trial-ending reminder and payment-method policy, and convert it to a paying subscription.
weight: 88
---

# Run a trial to conversion

A subscribe-with-trial opens a subscription **Trialing** — serving its plan, charging
nothing — until it converts to paying **Active**. Background:
[Subscriptions & lifecycle](../concepts/subscriptions-and-lifecycle.md).

## Open a trial

```bash
curl -s -X POST http://localhost:8000/api/v1/subscriptions \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Idempotency-Key: subscribe-org_123-trial-01" \
  -d '{ "org": "org_123", "plan": "team", "trial": true }'
```

- `"trial": true` uses the default length (`CBOX_BILLING_TRIAL_DAYS`, 14).
- `"trial_days": 30` sets an explicit length.

The subscription opens `Trialing` with a `trial_ends_at`.

## The policy knobs

`config/billing.php` → `trial`:

| Knob | Default | Effect |
| --- | --- | --- |
| `default_days` | 14 | Length when unspecified. |
| `reminder_lead_days` | 3 | Trial-ending email lead. |
| `require_payment_method` | false | When true, a due trial with no vaulted method is not charged. |
| `no_payment_method_action` | cancel | For a due trial with no method when required: `cancel` or `pause`. |

The default (`require_payment_method: false`) matches a manual/out-of-band gateway
that vaults nothing — a due trial always converts and its first invoice collects on
the ordinary charge/renewal path.

## Conversion

The scheduled pass converts due trials daily:

```
Schedule::command('billing:convert-trials')->dailyAt('04:00')
```

It takes each `Trialing` subscription whose `trial_ends_at` has passed to a paying
`Active` (first charge), and sends the trial-ending reminder as a trial crosses into
its lead window. It is idempotent — a converted trial is never re-selected. Run it
manually while testing:

```bash
php artisan billing:convert-trials
```

## Related documentation

- [Concepts → Subscriptions & lifecycle](../concepts/subscriptions-and-lifecycle.md)
- [Deployment → Operations](../deployment/operations.md)
