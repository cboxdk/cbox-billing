---
title: Issue an invoice + credit note
description: Trigger invoice issuance (scheduled or via the lifecycle service), download the PDF, and issue a credit note under the refund permission.
weight: 84
---

# Issue an invoice + credit note

Invoices are normally issued automatically — by the monthly pass and by cycle
renewal — but you can also issue them from the lifecycle flows and inspect them via
the API and console.

## How invoices get issued

- **Monthly pass:** `billing:invoice` (1st of month, 02:00). Add `--org=<id>` to limit
  it to one organization for a manual run:

  ```bash
  php artisan billing:invoice --org=org_123
  ```

- **Cycle renewal:** `billing:renew` issues the renewal invoice as it advances a
  period.
- **Immediate charges:** plan changes, quantity changes, and add-ons issue their
  prorated invoice through the lifecycle services (`GeneratesInvoices` /
  `InvoiceService`).

Each invoice is issued by a **seller entity of record**, which sets its tax outcome
and its per-entity legal number. See [Invoicing & tax](../concepts/invoicing-and-tax.md).

## List and view invoices

```bash
curl -s http://localhost:8000/api/v1/invoices/org_123 \
  -H "Authorization: Bearer <token>"
```

In the console: Invoices (All / Open / Paid / Drafts), a detail page, and a PDF at
`/invoices/{invoice}/pdf` (rendered with FPDF — pure PHP, no headless browser). The
customer portal serves the same PDF at
`/billing/portal/{token}/invoices/{invoice}/pdf`.

## Issue a credit note

Refunds and credit notes are ledger reversals in the engine, surfaced under the
`invoices:refund` permission (see the [RBAC manifest](../identity/rbac-manifest.md)).
An operator with that permission issues a credit note against an invoice from the
console; the reversal posts to the ledger and the credit note carries the seller
entity's numbering. The reversal mechanics are the engine's — see the engine's
refunds documentation.

## Related documentation

- [Concepts → Invoicing & tax](../concepts/invoicing-and-tax.md)
- [Configuration → Tax & seller entities](../configuration/tax-and-sellers.md)
- Engine refunds: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
