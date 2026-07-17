---
title: Payment gateways
description: Configure Stripe, Mollie, or the manual signed-webhook gateway — how the bound PaymentGateway and webhook verifier are selected, deny-by-default.
weight: 22
---

# Payment gateways

Cbox Billing charges through a gateway-agnostic `PaymentGateway` contract. Exactly
one gateway is bound as the active one, selected by which credentials are set. The
engine's charging, dunning, and webhook ingest are the same regardless of gateway —
see [Payments & dunning](../concepts/payments-and-dunning.md).

## How the bound gateway is selected

`BillingServiceProvider` wires it deny-by-default:

- If **`STRIPE_SECRET`** is set, the `cboxdk/laravel-billing-stripe` adapter binds
  itself as the `PaymentGateway` and (with `STRIPE_WEBHOOK_SECRET`) its own webhook
  verifier. The app leaves those bindings untouched.
- Otherwise the dependency-free **`ManualPaymentGateway`** is the fallback, and the
  **manual HMAC webhook verifier** is bound — refusing every payload until
  `CBOX_BILLING_WEBHOOK_SECRET` is set.

So a fresh install runs on the manual gateway with no card settlement; setting the
Stripe keys flips the active gateway with no code change.

## Stripe

```dotenv
STRIPE_SECRET=sk_live_...
STRIPE_PUBLISHABLE=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

- `STRIPE_SECRET` — the server-side secret; its presence binds the Stripe gateway.
- `STRIPE_PUBLISHABLE` — browser-safe, used by the payment element in embedded and
  hosted flows.
- `STRIPE_WEBHOOK_SECRET` — verifies inbound Stripe webhooks. Without it, the app
  falls back to the manual verifier for signature checking.

Point Stripe's webhook at `POST /webhooks/stripe`. See the walkthrough in
[Cookbook → Configure Stripe](../cookbook/configure-stripe.md). The adapter's own
behaviour is documented in
[`cboxdk/laravel-billing-stripe`](https://github.com/cboxdk/laravel-billing-stripe).

## Mollie

Mollie is available as `cboxdk/laravel-billing-mollie` and binds the same
`PaysInvoices` / `WebhookVerifier` contracts. It is surfaced in the Settings →
Payment gateways list and flips to **connected** when its key (`MOLLIE_KEY`) is
set. Install the adapter and set its key to activate it.

## Manual / bank transfer

The manual gateway settles **out of band**: an operator (or a provider adapter)
posts a signed settlement webhook to `POST /webhooks/{gateway}`.

```dotenv
CBOX_BILLING_WEBHOOK_SECRET=<a strong shared secret>
CBOX_BILLING_WEBHOOK_SIGNATURE_HEADER=X-Cbox-Signature
```

Verification is **deny-by-default**: with no secret configured, the verifier
refuses every payload rather than trusting it. The gateway is reported "connected"
in Settings once the secret is present. The ingest is exactly-once, so a
re-delivery is a safe no-op.

## The Settings → Payment gateways panel

`config/billing.php` → `gateways` drives the console panel. Each gateway is listed
as available and flips to **connected** when its credential is set:

| Gateway | Mode | Connected when |
| --- | --- | --- |
| Manual / bank transfer | signed-webhook | `CBOX_BILLING_WEBHOOK_SECRET` set |
| Stripe | adapter | `STRIPE_SECRET` set |
| Mollie | adapter | `MOLLIE_KEY` set |

## Related documentation

- [Concepts → Payments & dunning](../concepts/payments-and-dunning.md)
- [API → Hosted checkout & portal](../api/hosted-checkout-and-portal.md)
- [Cookbook → Configure Stripe & verify a webhook](../cookbook/configure-stripe.md)
- [Security → Posture](../security/posture.md)
