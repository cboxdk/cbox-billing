---
title: Conversion attribution
description: The anonymous, privacy-preserving visitor id; how impressions are deduped once per visitor; and how a checkout start and its settlement are attributed back to the assigned variant idempotently through the checkout deep-link.
weight: 30
---

# Conversion attribution

## The anonymous visitor id

Sticky assignment and dedup need a stable per-visitor handle. That handle is a **random
32-hex-char token in a first-party cookie** (`cbox_vid`) — **not** a customer id, email, IP, or
fingerprint. It exists only to make a visitor's assignment stable and to dedupe
impressions/conversions; it carries no personal data, is never joined to a customer, and an
operator can drop it entirely without affecting billing.

Cookie policy: `SameSite=Lax`, `HttpOnly` (the server is the only reader), one-year lifetime. A
cross-site **embed** (an iframe on a third-party marketing site) will not receive a `Lax`
first-party cookie, so an embedded visitor is treated as a fresh anonymous visitor on each load —
a deliberate privacy trade-off. It lives in `App\Billing\Experiments\VisitorIdentity`.

## Impressions

When a running experiment serves a variant's table, an **impression** is recorded for
`(variant, visitor)` — **deduped to once per visitor per variant** by a UNIQUE database index, so
a refresh or a return visit never inflates the denominator. Recording is best-effort: it never
breaks serving the page.

## Conversions — the two moments

A conversion is attributed through the [checkout deep-link](../storefront/checkout-deep-link.md).
When a running experiment serves a variant, its attribution triple is threaded onto every CTA
link as three query params:

| Param | Value |
| --- | --- |
| `cbox_exp` | the experiment key |
| `cbox_var` | the assigned variant id |
| `cbox_vid` | the anonymous visitor id |

The operator's checkout entry forwards these to `POST /api/v1/checkout-sessions` (as
`experiment` / `variant` / `visitor`). From there:

1. **Checkout started** — the API mints the hosted checkout session and records a
   `checkout_started` conversion, stamped with the session id. The recorder validates the variant
   belongs to a **running** experiment whose key matches (deny-by-default — a stale link to a
   concluded or renamed experiment records nothing).
2. **Checkout completed** — when the gateway's settled webhook activates the subscription, the
   settlement finds the started conversion(s) for that session and records the matching
   `checkout_completed` conversion.

## Idempotency

Every conversion row is UNIQUE on `(variant, visitor, kind)`. So a **double checkout-start** (a
replayed CTA link) or a **re-delivered settlement webhook** hits the constraint and is swallowed —
a conversion is counted **at most once**. The completion path also inherits the webhook ingest's
own settle-once guard, so a double settlement never double-counts.

The recorder is `App\Billing\Experiments\ConversionAttribution`; the completion hook lives in the
existing `CheckoutActivation` decorator so it runs on the same exactly-once settled webhook that
creates the subscription.
