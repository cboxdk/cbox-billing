---
title: Documented seams
description: The boundaries Cbox Billing documents honestly — authenticated ERP push, live marketplace payouts, statutory tax-filing submission, and live gateway verification — where the app defines the contract but the real external integration is the deployment's responsibility.
weight: 92
---

# Documented seams

Cbox Billing is honest about where a boundary is a **contract the app defines** rather
than a fully-verified integration against a live third party. These seams are not
hidden — naming them plainly is part of the security posture. Where a seam involves
real money or a statutory filing, treat crossing it as **your** integration and
verification responsibility.

This is distinct from the app's internal host seams (`app/Billing/Seams/*`), which are
the engine ports the app binds to durable implementations — those are wired and
tested. The seams below are **external** integration boundaries.

## Authenticated ERP / accounting push

The `cbox-billing-connectors` plugin exports billing documents (invoices, payments,
credit-notes) to an accounting/ERP system over HTTP/JSON or NDJSON, with an idempotent
per-document sync ledger. The app defines the export contract and the authenticated
push (a signed HTTP endpoint via `BILLING_CONNECTOR_HTTP_ENDPOINT` +
`BILLING_CONNECTOR_HTTP_SECRET`), but the **specific ERP's** authentication, schema,
and acceptance are the deployment's to wire and verify. The app does not claim a
certified, out-of-the-box integration with any particular ERP.

## Live marketplace payouts

The `cbox-billing-marketplace` plugin covers marketplace billing concerns. **Live
payout execution** — actually moving money to sellers through a payment provider — is
a seam: the app models the marketplace shape, but executing real payouts against a
live provider account is a deployment integration you must configure and verify.

## Statutory tax-filing submission

The `cbox-billing-tax-plus` plugin **prepares** filings on the open `laravel-tax`
engine — HMRC MTD 9-box VAT and EU OSS per-member-state payloads — and provides a
console to review and mark them filed. Actual **statutory submission** to a tax
authority (e.g. the live HMRC MTD API) is a documented seam: the app produces and
lets you review the payload; connecting to and submitting through the authority's live
endpoint, and the legal responsibility for the filing, are yours.

## Live payment-gateway verification

The Stripe and Mollie adapters bind the app's `PaysInvoices` / `WebhookVerifier`
contracts, and the app verifies signatures and ingests settlement exactly-once. What
the app does **not** do for you is prove your specific live gateway account is wired
end-to-end: you must run the [configure-and-verify recipe](../cookbook/configure-stripe.md)
against your account — confirm the gateway binds, a real webhook verifies, and a
settlement marks its invoice paid — before trusting production money flow.

## What is NOT a seam

To be equally clear about what is solid:

- The **ledger, event log, reconciliation, currency lock, and wallet** are durable and
  engine-backed — the money source of truth, not a stub.
- **Enforcement** (reserve/commit, the three-way outcome) is real and wired.
- **Webhook signature verification** and **exactly-once ingest** are real.
- **Per-org tenant scoping** and **API token auth** are enforced on every request.
- **On-prem license signing/verification** uses real Ed25519 crypto
  (`cboxdk/license`).

## Related documentation

- [Posture](posture.md)
- [Open core → Commercial plugins](../open-core/commercial-plugins.md)
- [Cookbook → Configure Stripe + verify a webhook](../cookbook/configure-stripe.md)
