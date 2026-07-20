---
title: The checkout deep-link contract
description: How a pricing-table CTA hands off into checkout — the placeholder/query template — and why a public, pre-customer table cannot mint a hosted checkout session itself, versus the paywall which links straight to hosted checkout.
weight: 50
---

# The checkout deep-link contract

## Why a table hands off rather than links straight to checkout

The hosted checkout is addressed by an opaque, **organization-scoped** session token
(`/billing/checkout/{token}`), minted by `POST /api/v1/checkout-sessions` for a specific org. A
public pricing table is **pre-customer** — a marketing-page visitor has no organization yet — so
the table cannot mint a session. Instead its CTA **hands off** to the operator's own checkout /
signup entry point, carrying the chosen plan, currency and interval; that authenticated entry
(which has the org in hand) then calls `POST /api/v1/checkout-sessions` to mint the real hosted
checkout URL.

This is the honest hosted-vs-embeddable boundary:

- **The public pricing table** hands off (it has no org).
- **The paywall** links **straight** to the hosted checkout — it already knows the org, so it
  reuses the `UpgradeGate`'s pre-built `/billing/checkout/{token}` deep-link.

## The CTA target

Each table sets a **CTA target** (`cta_url_template`). The chosen values are substituted for the
placeholders, URL-encoded:

| Placeholder   | Value                                   |
| ------------- | --------------------------------------- |
| `{plan}`      | the plan key (the annual key when yearly is selected) |
| `{currency}`  | the selected ISO currency               |
| `{interval}`  | `month` or `year`                       |
| `{price}`     | the price in minor units                |

```
https://app.example.com/signup?plan={plan}&currency={currency}&interval={interval}
→ https://app.example.com/signup?plan=team&currency=EUR&interval=month
```

A target with **no** placeholders instead gets the values appended as a query string
(`?plan=…&currency=…&interval=…&price=…`, merged with any query it already carries).

## Resolution order

Deny-by-default, so a CTA is always a valid link:

1. the table's own `cta_url_template`, when set;
2. otherwise the configured `billing.storefront.checkout_url`
   (`CBOX_BILLING_STOREFRONT_CHECKOUT_URL`);
3. otherwise the app-root path — a relative link, so a table with no configured target stays
   fully self-contained (no external host in its CTA).

## What the operator's checkout entry receives

Your authenticated checkout/signup route reads the plan + currency + interval, resolves the
visitor's (new or existing) organization, and calls:

```
POST /api/v1/checkout-sessions   { org, plan, currency, return_url }
→ { url: "https://your-host/billing/checkout/<token>", expires_at }
```

then redirects the customer to that `url`. From there the hosted checkout owns the payment.
