---
title: Order form & acceptance
description: The hosted, seller-branded, self-contained (CSP-safe) order form at a tokenized URL; the e-signature-by-acceptance evidence (typed name + explicit agreement + captured timestamp/IP → an immutable record); token isolation; and the signature-provider seam for DocuSign and friends.
weight: 40
---

# Order form & acceptance

Sending an approved quote mints an opaque token and opens a **public, no-auth** order form at
`/quote/{token}` (`App\Http\Controllers\OrderFormController`). The token is the whole
authorization — an unknown or wrong token 404s, so a customer only ever sees their own quote
(cross-quote isolation).

## The page

The order form is **seller-branded** (accent, logo/wordmark, legal line resolved through the shared
`BrandingResolver`) and **fully self-contained**: inline CSS, a same-origin `POST` form, and **no
external stylesheet, script, font or host** — the same CSP-safe discipline as the embeddable
storefront pricing table. It renders the line items, the contract terms, the first-invoice total,
the committed contract value, the validity window, and the accept / decline actions.

## E-signature by acceptance

Acceptance is an **e-signature-by-acceptance**: the customer types their full name, ticks an
explicit agreement checkbox, and submits. `App\Billing\Cpq\QuoteAcceptanceService` captures the
signature through the `CapturesSignature` seam and writes an **immutable** acceptance record
(`App\Models\QuoteAcceptance`) carrying:

- the typed signer name (and optional email),
- the explicit agreement flag,
- the captured **timestamp**, **IP** and user agent,
- the signature provider (`null` for the in-house acceptance),
- a snapshot of the accepted first-invoice total and committed value.

Deny-by-default: a blank name or an unticked agreement box is refused — nothing is recorded and no
subscription is provisioned. Acceptance is idempotent (a re-accept returns the existing record) and
audit-logged as `quote.accepted`; decline is recorded as `quote.declined`.

## The signature-provider seam (boundary)

`App\Billing\Cpq\Contracts\CapturesSignature` is a **seam**, not a fabricated integration. The
default `NullSignatureProvider` is the honest in-house acceptance described above — a typed name +
agreement, with the server-captured timestamp and IP as the evidence; no document is sent anywhere.

A host that wants a certificate-backed provider (**DocuSign**, Adobe Sign, Scrive, …) binds their
own implementation of `CapturesSignature` and returns the provider's envelope reference, which is
stored on the acceptance record. **This application ships only the null provider** and does not
fabricate a third-party e-signature integration. The provider is selected by
`billing.quotes.signature.provider` (only `null` ships here); the binding lives in
`App\Providers\CpqServiceProvider`.

> **Honest scope.** The in-house acceptance is tamper-evident (immutable record, audit chain), not a
> qualified/advanced electronic signature. Where a jurisdiction or contract requires a certified
> provider, bind one to the seam.
