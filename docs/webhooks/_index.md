---
title: Outbound webhooks
description: The integrator event bus — register endpoints, subscribe to the billing event catalog, and verify signed deliveries. Covers the signing scheme, the event catalog, retries/idempotency, and the SSRF guard.
weight: 45
---

# Outbound webhooks

Cbox Billing delivers its billing lifecycle to your systems as **signed, outbound HTTP webhooks** — the primary integration path for keeping an external system (a CRM, a data warehouse, a provisioning service) in sync with subscriptions, invoices, payments, and licenses.

You register an **endpoint** (a URL + a set of subscribed event types) in the console under **Settings → Webhooks**. When a billing event occurs, the app enqueues a signed `POST` to every active endpoint subscribed to that event type. Delivery is queued (never on the request hot path), retried with exponential backoff, and idempotent.

This differs from the app's **inbound** webhooks (payment-gateway settlement and Cbox ID provisioning), which the app *receives*. Outbound webhooks are what the app *sends*.

## In this section

- **[Signing & verification](signing.md)** — the HMAC-SHA256 envelope, the `X-Cbox-Signature` / `X-Cbox-Timestamp` headers, and a copy-paste verification snippet.
- **[Event catalog](event-catalog.md)** — every outbound event type and the billing moment that fires it.
- **[Delivery, retries & idempotency](delivery.md)** — queueing, backoff, dead-lettering, the retry sweep, and how to dedupe.
- **[Security & SSRF](security.md)** — how endpoint URLs are guarded against server-side request forgery.
