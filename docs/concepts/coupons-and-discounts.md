---
title: Coupons and discounts
description: Authored promo codes that reduce the net (pre-tax) amount through the billing engine — percentage or fixed, with once/repeating/forever duration, redemption limits, expiry, and plan scope — surfaced at checkout, subscribe, and every renewal.
weight: 42
---

# Coupons and discounts

A **coupon** is an authored discount — a percentage off, or a fixed amount off — redeemable
as a promo code at checkout and subscribe. Cbox Billing **surfaces** the engine's coupon
primitive end to end; it never hand-rolls discount arithmetic. Every money reduction is the
engine `Cbox\Billing\Pricing\CouponApplier` applied to a `Cbox\Billing\Pricing\ValueObjects\Coupon`,
so a coupon reduces the **net (pre-tax) amount** and the quote builder taxes the reduced net.

## The model

| Concept | What it is | Where it lives |
| --- | --- | --- |
| **Coupon** | The authored discount + its lifecycle: code, type, duration, limits, expiry, plan scope. | `coupons` |
| **Redemption** | One redemption by an organization — the append-only ledger the limits are enforced against. | `coupon_redemptions` |
| **Binding** | A snapshot of the discount bound to a subscription, with a remaining-periods counter. | `subscription_coupons` |

A `Coupon` maps to the engine value object through `Coupon::toEngineCoupon()`:

| App field | Engine (`DiscountType`) |
| --- | --- |
| `discount_type = percent`, `percent_off` | `DiscountType::Percentage`, `percentage` |
| `discount_type = fixed_amount`, `amount_off_minor` + `currency` | `DiscountType::Fixed`, `amount` (`Money`) |
| `redeem_by` | `validUntil` |

## Discount type

- **Percentage** (1–100) — `CouponApplier` uses `Money::proratedBy(100 − p, 100)`. 20% off a
  10 000 net is 8 000.
- **Fixed amount** — a `Money` in a required `currency`, floored at zero, and only applicable
  to a charge in the same currency (deny-by-default).

## Duration

The duration decides how long a redeemed coupon keeps discounting a subscription. The
binding's `remaining_periods` counter encodes it, and the renewal invoicer decrements it per
issued period invoice:

- **`once`** — the first invoice only (`remaining_periods` opens at 1); every renewal is full
  price.
- **`repeating`** — the next **N** invoices (`duration_in_periods`), then stops.
- **`forever`** — every renewal, indefinitely (`remaining_periods` is null).

A `repeating` or `forever` coupon also reduces reported **MRR** (net of the recurring
discount), so revenue is never over-counted; a `once` coupon does not touch recurring
revenue.

## Limits, expiry, and scope

- **`max_redemptions`** — a per-coupon cap. Redemption locks the coupon row
  (`SELECT … FOR UPDATE`) before the check-and-insert, mirroring the seat-assign lock, so
  concurrent redeemers can never push `times_redeemed` past the cap.
- **`max_redemptions_per_customer`** — an optional per-organization cap.
- **`redeem_by`** — an expiry; a redeemed forever/repeating discount keeps applying after the
  code itself expires (expiry gates *new* redemptions, not existing discounts).
- **`applies_to`** — `all` plans, or an explicit allow-list of plan keys (`plans`).
  Deny-by-default: a `plans`-scoped coupon refuses any plan not on its list.

## Redemption — deny-by-default

Redeeming a code validates it first: an unknown, inactive, archived, expired, over-limit, or
not-applicable code is refused with a specific, customer-facing error. A valid code is
redeemed and bound **atomically** — the redemption ledger row and the subscription binding
are written under the coupon-row lock, so `max_redemptions` holds under concurrency.

The promo-code field is wired into every money entry point:

- **Hosted checkout** — the up-front charge is discounted through the applier and the code is
  redeemed + bound when the settled webhook activates the subscription.
- **Customer portal** plan change — binds the coupon so renewals of the new plan are
  discounted.
- **Management API** `POST /api/v1/subscriptions` (and the checkout-session endpoint) — the
  code is validated (a `422` on refusal) *before* the subscription opens, then redeemed +
  bound.
- **Console** subscribe.

## preview == charge

A bound coupon becomes a real, **engine-taxed discount line** on the invoice — a negated net
the quote builder taxes at the same rate as the plan line — never a hand-subtracted total. The
same `CouponDiscounter` seam computes the preview and the charge, so the discounted amount a
customer previews is by construction the amount they are billed, and the invoice totals reflect
the discounted net plus its tax exactly.

## Console

Coupons are managed under **Catalog → Coupons** (gated `catalog:read` to view,
`catalog:manage` to write): a searchable list, a detail page (definition, redemption count vs
limit, and the redemption ledger with organization/subscription cross-links), and
create/edit/archive/delete. A coupon that has ever been redeemed is **archived**, never
hard-deleted, so its ledger and any live discounts are preserved; only a never-redeemed
coupon is removed outright.

## Related

- [Catalog and pricing](catalog-and-pricing.md) — the plans a coupon discounts.
- [Invoicing and tax](invoicing-and-tax.md) — how the discount line is taxed and shown.
- [Subscriptions and lifecycle](subscriptions-and-lifecycle.md) — the binding across renewals.
