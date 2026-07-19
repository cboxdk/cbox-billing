---
title: Tax exemption certificates
description: Capturing, verifying and applying US B2B tax exemption certificates (resale, nonprofit, government) so an exempt customer is not charged tax for the jurisdiction the certificate covers — deny-by-default, with preview == charge.
weight: 46
---

# Tax exemption certificates

Some business customers are exempt from tax in a jurisdiction — a reseller with a
resale certificate, a nonprofit, or a government buyer. Cbox Billing captures those
certificates, runs them through an operator verify/reject lifecycle, and **applies**
a verified one so the customer is not charged tax for the jurisdiction it covers.

The rule is **deny-by-default**: only a `verified`, non-expired certificate whose
jurisdiction matches the transaction's place of supply exempts, and it exempts **only
that jurisdiction**. Everything else is still taxed.

## The model

A `TaxExemptionCertificate` (table `tax_exemption_certificates`) belongs to an
organization and carries:

| Field | Meaning |
| --- | --- |
| `jurisdiction` | The scope: an ISO 3166-2 subdivision (`US-CA`), or a country / federal code (`US`, `DK`). |
| `exemption_type` | `resale` \| `nonprofit` \| `government` \| `other`. |
| `certificate_number` | The certificate/permit number (per-type sanity-checked on upload). |
| `issued_at` / `expires_at` | `expires_at` null = no expiry. |
| `status` | `pending` \| `verified` \| `rejected` \| `expired`. |
| `document_path` | The uploaded certificate, stored on the **private** disk. |
| `verified_by_sub` / `verified_at` | Who decided it, and when. |

### Secure storage

The uploaded document (PDF or image, size/type validated) is stored on the private
`local` disk under `tax-exemptions/{org}/…`, never the public disk. It is only ever
served through the authz-gated console download route, which streams the file after
checking the certificate belongs to the org in the route.

## Lifecycle

1. **Upload** (operator on the customer page, or the customer in the portal) — the
   certificate lands `pending`. Validation checks the jurisdiction, a per-type
   certificate-number shape, a future `expires_at` if given, and the document.
2. **Verify / reject** (operator only) — flips the status to `verified` / `rejected`
   and records the operator `sub`. Only a `pending` certificate is reviewable; a
   customer can never self-verify.
3. **Expire** — the scheduled `tax:expire-certificates` command (daily) flips
   `pending`/`verified` certificates whose `expires_at` has passed to `expired`.

`TaxExemptionCertificate::isActiveNow()` is the belt to that command's braces: a
verified-but-past-expiry certificate does not exempt at calculation time even before
the sweep runs, so a late sweep can never mis-charge.

## How the exemption is applied to tax

This is the core. The exemption is fed into the existing tax path as a **zero-rate /
exempt decision** — the app never hand-rolls tax math.

The tax engine's `TaxCalculator` is org-blind: its `TaxQuery` carries the place of
supply but not the buyer, while an exemption is a property of *(buyer, jurisdiction)*.
The app bridges that gap at **its own tax-context layer**, without touching the tax
package:

- `TaxContextFactory::forOrganization()` — the single chokepoint every app quote path
  (invoice preview, invoice issue, proration, plan-change) already builds through —
  loads the org's verified, non-expired certificates into a request-scoped
  `ExemptionContext` right before the quote is built.
- `ExemptingTaxCalculator` decorates the engine's `TaxCalculator`. For each line it
  first asks the wrapped calculator for its verdict (nexus, taxability, rate — all the
  engine's logic). **Only** when that verdict would actually collect tax
  (`TaxTreatment::Standard`) *and* the buyer holds a matching certificate does it flip
  the line to `TaxTreatment::Exempt`: the engine-computed net is kept, the tax becomes
  zero, gross equals net — exactly the shape the engine's own exempt branch produces.

Because both preview and charge run through the same `TaxContextFactory` →
`ExemptingTaxCalculator` seam with the same certificates loaded, **preview == charge**
by construction. The applied certificate is stamped on the invoice
(`exemption_certificate_id` + `exemption_reason`) and each line records the engine's
verdict (`tax_treatment` / `tax_note` / `tax_rate`), so an exempt invoice is legible as
an audit trail on the document itself.

### What is *not* exempted

- A jurisdiction the customer has no certificate for — still taxed.
- A `pending` / `rejected` / `expired` certificate — never exempts.
- **EU VAT reverse-charge** — untouched. Reverse-charge is not a tax the seller
  collects, so it never reaches the `Standard` branch the decorator acts on; a
  certificate cannot change it.

## Console & portal

- **Console** (customer detail, gated `customers:read` to view / `customers:manage`
  to write): list an org's certificates, upload, verify/reject (with a confirm), and
  download the document (authz-checked). A **Tax exemptions** overview
  (`/tax-exemptions`) shows who is exempt where.
- **Portal** (customer self-serve): the customer uploads a certificate — it lands
  `pending` for operator review — and sees its status.

## A note for the tax package

The exemption is applied at the **app's** tax-context layer, not in
[`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax). That is deliberate: the
tax package stays exemption-agnostic and the app owns the *(buyer, jurisdiction)*
policy. A cleaner long-term home would be a **first-class customer-exemption concept in
the tax package** — e.g. an optional `exemption` input on `TaxQuery` (or an
`ExemptionResolver` seam the regime consults) yielding a native `Exempt` assessment
with the certificate reference. Until the package grows that seam, the decorator is the
honest integration point — it composes the engine's real verdict rather than faking a
package capability.

## Related documentation

- [Invoicing & tax](invoicing-and-tax.md)
- [Configuration → Tax & seller entities](../configuration/tax-and-sellers.md)
- Tax engine: <https://github.com/cboxdk/laravel-tax>
