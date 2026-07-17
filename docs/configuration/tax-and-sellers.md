---
title: Tax & seller entities
description: Declare the selling entities of record — each with its own legal identity, tax registrations, currency, and per-entity invoice numbering — and how the issuing entity drives the tax outcome.
weight: 23
---

# Tax & seller entities

Cbox Billing issues invoices under **selling entities of record**. Each entity has
its own legal identity, tax registrations, currency, and invoice-number sequence.
The entity that issues an invoice drives its tax outcome, computed by the engine's
tax composition on top of [`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax).

## Declaring entities

Seller entities live in `config/billing.php` → `seller`:

```php
'seller' => [
    'default' => env('CBOX_BILLING_SELLER', 'cbox-dk'),

    'entities' => [
        'cbox-dk' => [
            'legal_name' => 'Cbox',
            'registration_number' => 'DK00000000',
            'establishment' => 'DK',
            'currency' => 'DKK',
            'invoice_prefix' => 'CBOX-DK',
            'tax_registrations' => [
                ['country' => 'DK', 'number' => 'DK00000000'],
            ],
        ],
    ],
],
```

> The shipped values are **synthetic demo identifiers** — replace them with your
> real registered legal name, registration number, and VAT/tax numbers in
> production. The app does not invent or validate these; you supply them.

Each field:

| Field | Meaning |
| --- | --- |
| `legal_name` | The legal name printed on invoices. |
| `registration_number` | Company registration number. |
| `establishment` | The entity's country of establishment (ISO). |
| `currency` | The entity's home currency. |
| `invoice_prefix` | Prefix for this entity's invoice-number sequence. |
| `tax_registrations` | The `{country, number}` pairs this entity is registered in. |

## Routing and numbering

The app binds a `ConfiguredEntityRouter` (`EntityRouter` contract) from this config,
and a `DatabaseInvoiceNumberSequence` that gives each entity its own gapless,
per-entity invoice number sequence on the durable connection. The issuing entity is
therefore both the tax authority and the numbering authority for an invoice. See
[Invoicing & tax](../concepts/invoicing-and-tax.md).

## Tax

Tax lines are composed by the engine's quote/invoice module using the tax engine —
place of supply, reverse charge, and (in the EU) VAT treatment flow from the
issuing entity's establishment and registrations against the customer's location.
The **app** declares the entities and their registrations; the **tax engine** owns
the calculation. For the calculation rules see
[`cboxdk/laravel-tax`](https://github.com/cboxdk/laravel-tax).

Advanced statutory **filing** (HMRC MTD 9-box VAT, EU OSS per-member-state payloads)
is not in the open app — it is the `cbox-billing-tax-plus` commercial plugin. See
[Open core → Commercial plugins](../open-core/commercial-plugins.md).

## Related documentation

- [Concepts → Invoicing & tax](../concepts/invoicing-and-tax.md)
- [Cookbook → Add a seller entity for a jurisdiction](../cookbook/add-a-seller-entity.md)
- Tax engine: <https://github.com/cboxdk/laravel-tax>
