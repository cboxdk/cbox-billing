---
title: Configure Stripe + verify a webhook
description: Bind the Stripe gateway with its keys, point Stripe's webhook at the app, and confirm settlement is ingested exactly once.
weight: 85
---

# Configure Stripe + verify a webhook

Setting the Stripe keys flips the active `PaymentGateway` from the manual fallback to
Stripe — no code change. This recipe binds it and confirms a settlement round-trip.

## 1. Set the keys

```dotenv
STRIPE_SECRET=sk_live_...
STRIPE_PUBLISHABLE=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

When `STRIPE_SECRET` is set, the `cboxdk/laravel-billing-stripe` adapter binds itself
as the gateway and its own webhook verifier. `STRIPE_PUBLISHABLE` is browser-safe
(the payment element); `STRIPE_WEBHOOK_SECRET` verifies inbound webhooks. Never commit
real keys.

Re-cache config after changing env in production:

```bash
php artisan config:cache
```

## 2. Confirm the gateway is bound

Console → Settings → Payment gateways shows **Stripe: connected** once `STRIPE_SECRET`
is present (the manual gateway is no longer the fallback). See
[Payment gateways](../configuration/payment-gateways.md).

## 3. Point Stripe's webhook at the app

Create a webhook endpoint in the Stripe dashboard pointing at:

```
https://billing.example.com/webhooks/stripe
```

The endpoint is **public** (no bearer token) — authenticity is the Stripe signature
the bound verifier checks, not an API token. It is rate-limited per source IP
(`cbox-webhook`, 120/min).

## 4. Verify settlement

Trigger a test event (Stripe CLI `stripe trigger …`, or a real test charge). Confirm:

- The signature verifies (an invalid signature is rejected).
- The invoice is marked paid and a receipt is queued.
- **Exactly-once:** re-deliver the same event — the second delivery is a safe no-op
  (the app binds durable `ProcessedEventStore` / `SettledPaymentStore`).

If the settled event references a hosted checkout, its subscription is activated on
this webhook (the `CheckoutActivation` decorator) — a checkout never creates a paying
subscription before money settles.

## Mollie

The same flow applies with `cboxdk/laravel-billing-mollie` and `MOLLIE_KEY`; the
adapter binds the same contracts and the webhook is `/webhooks/mollie`.

## Related documentation

- [Configuration → Payment gateways](../configuration/payment-gateways.md)
- [Concepts → Payments & dunning](../concepts/payments-and-dunning.md)
- Stripe adapter: <https://github.com/cboxdk/laravel-billing-stripe>
