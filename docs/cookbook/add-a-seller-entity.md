---
title: Add a seller entity for a jurisdiction
description: Register a new selling entity of record with its own legal identity, tax registrations, currency, and invoice numbering, and make it the default or route to it.
weight: 90
---

# Add a seller entity for a jurisdiction

To invoice under a second legal entity — say a German GmbH alongside a Danish ApS —
add a seller entity. Each entity issues invoices under its own legal identity, tax
registrations, currency, and per-entity number sequence, and the issuing entity drives
the tax outcome. Background: [Tax & seller entities](../configuration/tax-and-sellers.md).

## 1. Declare the entity

Add it to `config/billing.php` → `seller.entities`, keyed by a short slug:

```php
'entities' => [
    'cbox-dk' => [ /* … existing … */ ],

    'cbox-de' => [
        'legal_name' => 'Cbox GmbH',
        'registration_number' => 'HRB000000',
        'establishment' => 'DE',
        'currency' => 'EUR',
        'invoice_prefix' => 'CBOX-DE',
        'tax_registrations' => [
            ['country' => 'DE', 'number' => 'DE000000000'],
        ],
    ],
],
```

> Use your **real** registered legal name, registration number, and VAT numbers — the
> shipped values are synthetic placeholders. The app does not invent or validate
> these.

The new entity gets its **own** gapless invoice-number sequence under its
`invoice_prefix` (via `DatabaseInvoiceNumberSequence`).

## 2. Make it the default (optional)

```dotenv
CBOX_BILLING_SELLER=cbox-de
```

`config/billing.php` → `seller.default` reads this. The `ConfiguredEntityRouter`
routes to it. Re-cache config in production (`php artisan config:cache`).

## 3. Confirm the tax outcome

Because the **issuing** entity drives tax, an invoice from `cbox-de` is composed
against Germany's establishment and registrations (place of supply, reverse charge,
EU VAT) by the tax engine. The app supplies the entity and its registrations via
`TaxContextFactory`; the calculation is the tax engine's — see
[`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax).

## 4. See it in the console

Settings → Sellers lists the entities; Settings → Tax shows their registrations.

## Advanced filing

Statutory filing (HMRC MTD VAT, EU OSS payloads) is the `cbox-billing-tax-plus`
commercial plugin, not the open app. See
[Open core → Commercial plugins](../open-core/commercial-plugins.md).

## Related documentation

- [Configuration → Tax & seller entities](../configuration/tax-and-sellers.md)
- [Concepts → Invoicing & tax](../concepts/invoicing-and-tax.md)
