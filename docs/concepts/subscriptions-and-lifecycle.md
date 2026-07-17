---
title: Subscriptions & lifecycle
description: The subscription state machine — trials, Active/PastDue/Paused/NonRenewing/Canceled — plus ramps, minimum commitments, pause/resume, seat quantity, add-ons, and the scheduled passes that drive them.
weight: 42
---

# Subscriptions & lifecycle

A subscription binds an organization to a plan for recurring periods. Cbox Billing
stores it in the `subscriptions` table and drives its lifecycle through app services
(`SubscriptionService`, `SubscriptionDepthService`, `TrialService`,
`CycleRenewalService`, `RetentionService`) over the engine's subscription module.

## The states

| State | Meaning |
| --- | --- |
| **Trialing** | Serving the plan, charging nothing, until `trial_ends_at`. |
| **Active** | Paying, in a current period, renewing at period end. |
| **PastDue** | A renewal charge failed; smart-retry is chasing it. |
| **Paused** | Access and metering suspended until resumed. |
| **NonRenewing** | Scheduled to cancel at period end (still active until then). |
| **Canceled** | Ended. May be reactivated within the win-back window. |

The engine owns the state machine and the forfeiture-on-transition rules; the app
drives the transitions and presents `standing()` to the console and API.

## Trials

A subscribe-with-trial opens a subscription **Trialing**, serving its plan and
charging nothing until `trial_ends_at`. The scheduled `billing:convert-trials` pass
(daily, 04:00) converts a due trial to a paying **Active** (first charge) and sends
the trial-ending reminder as a trial crosses into its lead window.

Config (`config/billing.php` → `trial`):

- `default_days` (14) — length when the subscribe does not specify.
- `reminder_lead_days` (3) — trial-ending email lead.
- `require_payment_method` (false) — when true, a due trial with no vaulted method
  is not charged; it takes `no_payment_method_action` (`cancel` or `pause`).

See [Cookbook → Run a trial to conversion](../cookbook/trial-to-conversion.md).

## The renewal pass

`billing:renew` (daily, 03:00) drives cycle renewal for each active subscription:
it grants the recurring per-cycle credit allotments as they vest, advances the
period on its boundary, renews add-ons, and issues the renewal invoice. The granting
is idempotent and time-keyed, so a daily cadence drips finer-grained allotments and
rolls a period over exactly once. A renewal reminder goes out `reminder_lead_days`
(7) ahead.

## Management depth

`SubscriptionDepthService` backs the deeper operations, all with
preview-equals-charge proration (a preview equals the charge that would be applied):

- **Pause / resume** — suspend and lift access + metering.
- **Seat quantity** — change seats with prorated proration; `preview: true` computes
  without applying.
- **Add-ons** — attach an add-on **aligned** to the subscription period or on its
  **independent** anchor (day/month/interval), with an optional credit allotment;
  detach removes it.
- **Scheduled change** — apply a plan change now (`when: now`) or defer it to the
  current period end (`when: period_end`); a pending change surfaces distinctly.

Ramps (scheduled price steps over the term) and minimum commitments (a floor the
period bills up to) are engine subscription features the app exposes through this
service and the management API. Their mechanics are documented in the engine.

## Cancellation and win-back

`RetentionService` forks a cancellation into `immediate`, `period_end`, or `pause`
(a pause-instead-of-cancel save), capturing a `reason` + `feedback` for churn
analytics in the `subscription_cancellations` log regardless of mode. Reactivation
(win-back) resumes a paused subscription, undoes a scheduled period-end cancel, or
re-subscribes one canceled within `reactivation_window_days` (30). See
[Payments & dunning](payments-and-dunning.md) and [Analytics](analytics.md).

## The API and scheduled surface

- Management API: `POST /api/v1/subscriptions`, `/preview`, `/change`, `/cancel`,
  `/reactivate`, `/pause`, `/resume`, `/quantity`, `/addons`. See
  [API → Management](../api/management.md).
- Scheduled: `billing:renew`, `billing:convert-trials`,
  `billing:apply-scheduled-changes` (hourly), `billing:retry-payments`. See
  [Deployment → Operations](../deployment/operations.md).

## Related documentation

- [Catalog & pricing](catalog-and-pricing.md)
- [Payments & dunning](payments-and-dunning.md)
- Engine subscription internals: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
