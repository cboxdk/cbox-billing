---
title: Quote → subscription provisioning
description: How an accepted quote provisions a subscription through the engine SubscribesOrganizations seam — idempotently — the coupon hand-off, the payment-method handoff to hosted checkout, and the modeling boundary for commitment/ramp.
weight: 50
---

# Quote → subscription provisioning

On acceptance, `App\Billing\Cpq\QuoteProvisioner` (bound to the
`App\Billing\Cpq\Contracts\ProvisionsFromQuote` contract) turns the accepted quote into a real
subscription through the engine `App\Billing\Subscriptions\Contracts\SubscribesOrganizations`
seam — the same path the console and API use.

## What is provisioned

- The quote's **primary plan line** (the first recurring plan line) opens the subscription, with
  **seats = its quantity**, in the quote's currency.
- The quote's **order coupon**, if any, is redeemed onto the subscription so its cycles are
  discounted exactly as quoted.
- The subscription is linked back to the quote (`subscription_id` + `provisioned_at`), and the
  quote's detail cross-links to it.

## Idempotency

Provisioning is **idempotent**: the quote's `subscription_id` is the guard. A quote provisions at
most once — a re-accept (a retried request, a double click) returns the already-provisioned
subscription and never opens a second one. Acceptance and provisioning run in one transaction.

## Payment method

If the organization has no payment method on file, hand off to the **hosted checkout** to collect
one (see [Hosted checkout & portal](../concepts/_index.md)). The subscription is provisioned on
acceptance regardless; collection of a card is a separate, resumable step.

## Modeling boundary (flagged)

The durable engine `Subscription` row carries **no columns for a minimum commitment or a ramp
schedule**. Those committed terms are therefore **not** written onto the subscription — they live on
the **quote**, which is the CPQ **contract of record** and computes the committed value through the
engine `MinimumCommitment` and `RampSchedule` value objects. Provisioning wires the primary plan
line; the full contract (term, commitment, ramp, one-off and additional lines) is preserved on the
quote and its order form.

Multi-line / add-on provisioning (turning additional plan lines into subscription add-ons) is a
documented **extension point** on `QuoteProvisioner`, not yet wired — the primary-plan path covers
the common enterprise case (one plan, N seats, contract terms, a coupon).
