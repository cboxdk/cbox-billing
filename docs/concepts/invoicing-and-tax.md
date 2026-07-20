---
title: Invoicing & tax
description: Per-seller legal invoice numbering, pure-PHP PDF rendering, credit notes, the billing-currency lock, and how tax lines are composed by the tax engine from the issuing entity.
weight: 45
---

# Invoicing & tax

Invoicing turns a subscription period (or a one-off charge) into a tax-composed,
legally numbered document. Cbox Billing stores invoices in `invoices` /
`invoice_lines` and drives issuance through `InvoiceService` (`GeneratesInvoices`).

## Issuance

Invoices are issued by:

- the monthly pass `billing:invoice` (1st of month, 02:00), and the per-cycle
  renewal pass;
- the management/console flows for immediate charges (plan changes, quantity, add-ons).

Each invoice is issued **by a seller entity of record**, which drives both its tax
outcome and its number. Issuance queues the `InvoiceIssuedMail` notification.

## Legal numbering

The app binds a `DatabaseInvoiceNumberSequence` — a durable, **per-entity** gapless
sequence on the app's connection. Each seller entity has its own `invoice_prefix`
and sequence, so numbers are unique and monotonic per legal entity. The engine pairs
the first-finalize currency stamp with the number commit so a concurrent
first-finalize resolves to one currency (see the currency lock below). Sequences
live in the `invoice_sequences` table (credit notes in `credit_note_sequences`),
keyed by `(seller, environment)`.

### Numbering is plane-distinct

Two environments must never mint the same legal document number — a settlement
webhook carrying nothing but an invoice number would otherwise address two planes
at once. Numbering is therefore scoped to the plane in two places:

- **The prefix.** Production numbers under the seller's configured
  `invoice_prefix` verbatim, forever. Every other plane appends its own
  environment key, upper-cased: seller `CBOX-DK` in the `staging` plane numbers
  `CBOX-DK-STAGING-2026-00001` (and credit notes `CBOX-DK-STAGING-CN-2026-00001`).
  The derivation (`PlaneDocumentPrefix`) is deterministic and idempotent, applies
  both to a **cloned** seller and to the `billing.seller` **config fallback** a
  plane with no authored seller row resolves, and degrades to a short digest of the
  environment key when the key is too long to fit the authored 40-character width.
- **The counter.** Each `(seller, environment)` pair owns its own gapless
  sequence, so a sandbox draw can never consume — or gap — production's series,
  even where both planes resolve the same seller id.

Promotion never carries `invoice_prefix` across planes: an existing target seller
keeps its own numbering, and a seller a promotion *creates* gets the source prefix
rebased onto the target plane.

Environments cloned before this behaviour existed are corrected by the
`backfill_plane_distinct_seller_prefixes` migration, which rewrites only
non-production sellers whose prefix is shared across planes. Documents already
issued in those sandboxes keep their old numbers; the counter stays monotonic
across the change and production is never rewritten.

## The billing-currency lock

An account's currency is fixed by its **first finalized invoice** and is thereafter
one-way. The lock is keyed on the billing account alone (independent of any payment
method — it survives a card being added or removed). The app binds
`DatabaseBillingCurrencyLock`; `default_currency` (DKK) is only the last-resort
fallback before an account transacts and never overrides the lock. This is an engine
account invariant — see
<https://github.com/cboxdk/laravel-billing/tree/main/docs> → accounts.

## PDF rendering

Invoice PDFs render with **`setasign/fpdf`** (`InvoicePdfRenderer`) — pure PHP, no
headless browser, no external runtime. They are served from the console
(`/invoices/{invoice}/pdf`) and the customer portal
(`/billing/portal/{token}/invoices/{invoice}/pdf`).

## Credit notes and refunds

Refunds and chargebacks are first-class ledger reversals in the engine; the app
surfaces credit-note issuance under the `invoices:refund` permission. See
[Cookbook → Issue an invoice + credit note](../cookbook/invoice-and-credit-note.md)
and the engine's refunds documentation.

## Tax

Tax lines are composed by the engine's quote/invoice module on top of
[`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax): place of supply,
reverse charge, and EU VAT treatment flow from the **issuing entity's** establishment
and registrations against the customer's location. The **app** declares seller
entities and their tax registrations (see [Tax & seller entities](../configuration/tax-and-sellers.md));
the **tax engine** owns the calculation. `TaxContextFactory` assembles the context
the engine needs from the resolved seller and customer.

Customer **tax exemptions** (US resale / nonprofit / government certificates) are
captured, verified and applied so an exempt customer is not charged tax for the
jurisdiction the certificate covers — see
[Tax exemption certificates](tax-exemption-certificates.md).

Advanced statutory **filing** (HMRC MTD VAT, EU OSS payloads) is the
`cbox-billing-tax-plus` commercial plugin, not the open app — see
[Open core → Commercial plugins](../open-core/commercial-plugins.md).

## Related documentation

- [Configuration → Tax & seller entities](../configuration/tax-and-sellers.md)
- [API → Management](../api/management.md)
- [Cookbook → Issue an invoice + credit note](../cookbook/invoice-and-credit-note.md)
- Tax engine: <https://github.com/cboxdk/laravel-tax>
