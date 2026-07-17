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
live in the `invoice_sequences` table.

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

Advanced statutory **filing** (HMRC MTD VAT, EU OSS payloads) is the
`cbox-billing-tax-plus` commercial plugin, not the open app — see
[Open core → Commercial plugins](../open-core/commercial-plugins.md).

## Related documentation

- [Configuration → Tax & seller entities](../configuration/tax-and-sellers.md)
- [API → Management](../api/management.md)
- [Cookbook → Issue an invoice + credit note](../cookbook/invoice-and-credit-note.md)
- Tax engine: <https://github.com/cboxdk/laravel-tax>
