---
title: Event catalog
description: Every outbound webhook event type and the billing moment that fires it — invoices, payments, subscriptions, licenses, coupons, and dunning.
weight: 20
---

# Event catalog

An endpoint subscribes to one or more of these `type` values (deny-by-default: an unknown type cannot be subscribed). Each maps to a real billing domain event — there are no placeholder types.

| Type | Fires when | Key payload fields |
| --- | --- | --- |
| `invoice.issued` | An invoice is finalized to a legal number | `number`, `account`, `currency`, `issued_at`, `totals` |
| `payment.settled` | A payment settles against an invoice | `reference`, `amount`, `status`, `gateway_reference` |
| `payment.failed` | A dunning charge attempt fails (retry scheduled) | `invoice_id`, `subscription_id`, `attempt`, `max_attempts`, `next_attempt_at` |
| `credit_note.issued` | A credit note / refund reversal is issued | `number`, `invoice_number`, `account`, `net`, `tax`, `gross`, `reason`, `kind` |
| `subscription.created` | A subscription is opened | `id`, `organization_id`, `plan_id`, `status`, `seats`, period bounds |
| `subscription.changed` | A subscription's plan/price changes | `id`, `organization_id`, `price_id`, `change` |
| `subscription.renewed` | A subscription rolls to a new period | `id`, `organization_id`, `price_id`, `period_index` |
| `subscription.canceled` | A subscription is canceled immediately | `id`, `organization_id`, `plan_id`, `canceled_at` |
| `subscription.cancellation_requested` | A subscriber requests cancellation (before any state change) | `id`, `organization_id`, `account`, `reason`, `comment` |
| `license.issued` | An on-prem license is issued | `id`, `customer_id`, `deployment_id`, `plan`, validity window |
| `license.revoked` | A license is revoked | `license_id`, `reason` |
| `coupon.redeemed` | A coupon is redeemed against a subscription | `code`, `coupon_id`, `discount_type`, `subscription_id`, `organization_id` |
| `dunning.exhausted` | Dunning runs out of retries for an invoice | `invoice_id`, `subscription_id`, `attempts`, `amount` |

## Amounts

Monetary values are objects of `{ "minor": <int>, "currency": "<ISO-4217>" }` — minor units (e.g. øre/cents), never a float. Reconstruct the decimal amount yourself from the currency's exponent.

## A note on `ping`

The **Send test event** action delivers a signed envelope with `type: "ping"`. It is not a catalog event and is not deduped — use it only to verify wiring end-to-end.
