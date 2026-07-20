---
title: Field & unit mapping
description: The per-provider field-name, unit-convention and vocabulary mapping each adapter applies — Stripe/Chargebee minor units vs Recurly decimal major units, epoch vs ISO dates, and the status/discount/duration translations.
weight: 20
---

# Field & unit mapping

Each adapter's whole job is translating one provider's schema into the normalized model. The
mappings below are the source of truth (they mirror the adapter code); anything an adapter has to
**assume** rather than read is flagged.

## Units — the critical difference

- **Stripe** and **Chargebee** carry amounts in **integer minor units** already
  (`unit_amount`, `price`, `amount_off`, `discount_amount`, `subtotal`, `tax`, `total`) — passed
  through unchanged.
- **Recurly** carries plan and invoice amounts in **decimal major units** (`unit_amount` =
  `"49.00"`) — the adapter multiplies by 100 to reach minor units, so `"49.00"` becomes `4900`.
  **But** Recurly *coupon* fixed amounts are `discount_in_cents` (already minor) — a mixed
  convention within one provider, handled per field.

> **Assumption (flagged):** the decimal→minor conversion assumes a **two-decimal** currency. A
> zero-decimal currency (JPY, KRW) would need per-currency exponent handling; the supported
> currencies + fixtures are two-decimal.

## Dates

- **Stripe / Chargebee** — unix epoch seconds (`created`, `current_period_start`, `trial_end`).
- **Recurly** — ISO-8601 strings (`created_at`, `current_period_started_at`).

Both are parsed to UTC and preserved (see [historical dates](historical-dates.md)).

## Per-entity field mapping

| Normalized | Stripe | Chargebee | Recurly |
| --- | --- | --- | --- |
| Product | `products[].id` / `name` / `description` | `item_families[].id` / `name` | *(synthetic — Recurly plans carry no product)* |
| Plan | a `prices[]` record (`nickname` key, `recurring.interval`) | `plans[].id` / `name` / `period_unit` / `item_family_id` | `plans[].code` / `name` / `interval_unit`+`interval_length` |
| Price | `prices[].unit_amount` / `currency` | `plans[].price` / `currency_code` | `plans[].currencies[].unit_amount` / `currency` |
| Coupon | `coupons[].id` / `percent_off` / `amount_off` / `duration` / `duration_in_months` | `coupons[].discount_type` (`percentage`/`fixed_amount`) / `discount_percentage` / `discount_amount` / `duration_type` | `coupons[].discount_type` (`percent`/`dollars`) / `discount_percent` / `discount_in_cents` / `duration` |
| Customer | `customers[].id` / `name` / `email` / `currency` / `address.country` | `customers[].first_name`+`last_name` / `preferred_currency_code` / `billing_address.country` / `vat_number` | `accounts[].code` / `first_name`+`last_name` / `address.country` *(no currency — pinned at subscribe)* |
| Subscription | `subscriptions[].customer` / `items.data[0].price.id` / `status` / `quantity` / `current_period_*` / `discount.coupon.id` | `customer_id` / `plan_id` / `status` / `plan_quantity` / `current_term_*` / `coupon_id` | `account.code` / `plan.code` / `state` / `quantity` / `current_period_*` / `coupon_redemptions[0].coupon.code` |
| Invoice | `invoices[].number` / `subtotal` / `tax` / `total` / `status` / `lines.data[]` | `id` / `sub_total` / `tax` / `total` / `status` / `line_items[]` | `number` / `subtotal` / `tax` / `total` / `state` / `line_items[]` |

## Status vocabularies

Provider subscription statuses are mapped onto the app's
(`active` / `trialing` / `past_due` / `canceled` / `paused`):

- **Stripe** — `trialing`→trialing, `past_due`/`unpaid`→past_due, `canceled`/`incomplete_expired`→canceled, `paused`→paused, else active.
- **Chargebee** — `in_trial`→trialing, `cancelled`→canceled, `paused`→paused, `non_renewing`/`future`→active.
- **Recurly** — `expired`/`canceled`→canceled, `paused`→paused, `future`→active, else active.

## Coupon duration

`once` / `repeating` / `forever` — mapped from Stripe `duration`, Chargebee `duration_type`
(`one_time`/`limited_period`/`forever`) and Recurly `duration` (`single_use`/`temporal`/`forever`).

## Assumptions & boundaries

- **Coupons import as `all`-scope.** Provider plan-scoped coupons are imported applying to all
  plans (the app's coupon scope model differs); re-scope them in the console afterward if needed.
- **Recurly products are synthetic.** Recurly plans carry no product, so imported Recurly plans
  hang under a single per-source `imported-recurly` product.
- **Only monthly/yearly bill.** A plan whose interval is not monthly or yearly (a weekly plan, a
  multi-month term) is not coerced — it is [flagged as a conflict](idempotency-dry-run.md).
