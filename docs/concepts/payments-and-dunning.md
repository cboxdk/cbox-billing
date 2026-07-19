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

## Track 2 — adaptive smart-retry dunning (money)

When a **renewal charge fails**, the subscription moves to **PastDue** and the invoice
is retried on the gateway — but *how* it is retried depends on **why** it declined. Where
a static `[1, 3, 5, 7]` schedule treats every failure the same, adaptive dunning
classifies the gateway decline code into a **recovery category** and drives the schedule
off it (the bar set by Recurly/Stripe Smart Retries: recover materially more failed
payments without hammering dead cards). `billing:retry-payments` (daily, 06:30) fires
each due attempt; each is idempotent per `(invoice, attempt)`, so a daily cadence enacts
the schedule without double-charging. A retry that settles recovers the subscription to
**Active** and sends a receipt.

### The decline taxonomy

`DeclineClassifier` turns the gateway's opaque `failureReason` (a `decline_code` token,
or — for the Stripe adapter — the SDK's free-text message) into a canonical code + one of:

| Category | Example codes | Recovery |
|---|---|---|
| **hard** | `lost_card`, `stolen_card`, `expired_card`, `account_closed`, `fraudulent` | **No retries.** Retrying the same method cannot succeed — short-circuit to the terminal action + a "update your payment method" notice (+ a retention save-offer). A [card-updater](#card--account-updater-seam) push can re-open recovery. |
| **insufficient_funds** | `insufficient_funds` | Retry, but **spread wider, pulled toward payday anchors, weekends skipped** — retrying a short balance the moment it declines just declines again. |
| **try_again_later** | `do_not_honor`, `try_again_later`, `processing_error`, `call_issuer` | Retry on a **longer** backoff — the issuer asked us to back off. |
| **needs_action** | `authentication_required` | Retry on a short curve **and send an authenticate link** (SCA). |
| **recoverable** | `card_declined`, `generic_decline` | Retry on the base curve. |
| **unknown** | anything unrecognized | Retried conservatively on the base curve (never guessed into a non-retryable hard decline). |

An unclear decline degrades to `unknown` and is **retried**, never abandoned. A decline can
**escalate mid-flight** (a soft decline that later returns `stolen_card` stops the schedule).

### The adaptive strategy & timing heuristics

`AdaptiveRetryStrategy` computes each attempt's instant as a pure function of the first
failure + the per-category plan (so a test clock drives it exactly), applying:

- **per-category curves** — a Hard decline is never retried; insufficient-funds spreads
  wider; try-again-later runs longer; the rest ride the base curve.
- **payday alignment** — an insufficient-funds attempt is pulled forward to the next
  configured payday day-of-month.
- **weekend avoidance** — an attempt landing on a quiet weekday is pushed to the next weekday.
- **max window** — an attempt beyond the recovery window is dropped (the schedule exhausts).

Strategies are configured under `config/billing.php` → `dunning.strategies` (per-category
`backoff_days` / `retry` / `avoid_weekends` / `align_to_payday`, plus the global
`max_window_days` / `payday_days` / `quiet_weekdays`) and **tunable at runtime** from the
console (Settings → Retry strategy), persisted to `dunning_strategies` and read live. A
category with no override inherits the base `payment.retry.schedule`, so an untuned
deployment behaves exactly as the legacy fixed schedule. `terminal_action` (`cancel` /
`none`) still governs the exhaustion outcome.

Every step is written to an append-only **attempts timeline** (`payment_retry_attempts`)
and sends a **decline-category-tailored email** (a hard decline asks for a new method; a
needs-action decline sends an authenticate link).

### Card / account-updater seam

A hard decline stops the retries — the **`UpdatesCards`** seam is how recovery re-opens
when a fresh card lands. A verified card-update webhook
(`POST /webhooks/{gateway}/payment-method`) points the account's vaulted default at the
new card and **immediately re-attempts** the account's in-dunning charges (including a
hard-declined charge whose subscription was left serving). Verification is deny-by-default
(`VerifiesCardUpdates`): the **Stripe** verifier consumes the real
`payment_method.automatically_updated` / `payment_method.updated` / `source.updated`
events (which the `laravel-billing-stripe` *settlement* adapter does not model), and a
manual HMAC verifier backs the manual gateway.

> **Stripe boundary (honest).** Stripe's automatic **account updater** — the networks
> pushing a new expiry/number and Stripe emitting `payment_method.automatically_updated` —
> is a **live Stripe feature** that must be enabled on the account and requires eligible
> network enrolment. This app builds the seam and consumes those real events when they
> arrive; it cannot manufacture them. Likewise, full-fidelity `decline_code` capture needs
> the Stripe adapter to propagate the SDK exception's structured code — today it flattens
> it to a message, which the classifier phrase-matches. See the code comments on
> `StripeCardUpdateVerifier` and `DeclineClassifier`.

### Recovery analytics

`RecoveryAnalytics` computes the payoff over real retry rows (plane-scoped): recovery rate
(recovered ÷ entered-dunning), recovery **by decline category**, average attempts-to-recover,
revenue recovered, and involuntary-churn averted. Surfaced on the dunning screen and a
dashboard card.

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
