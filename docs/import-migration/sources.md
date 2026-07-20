---
title: Supported sources
description: The three supported migration sources (Stripe, Chargebee, Recurly), which export files each adapter reads, and how to produce them.
weight: 10
---

# Supported sources

Three provider adapters ship today, each mapping that provider's export into the normalized model:

| Source | Adapter | Amount unit | Dates | Natural keys |
| --- | --- | --- | --- | --- |
| **Stripe** | `StripeAdapter` | integer minor units | unix epoch | opaque ids (`price_…`) |
| **Chargebee** | `ChargebeeAdapter` | integer minor units | unix epoch | ids |
| **Recurly** | `RecurlyAdapter` | **decimal major units** | ISO-8601 | `code` |

Adding another provider is a single class implementing `SourceAdapter`, registered in the
`AdapterRegistry` — no calling code changes.

## What to upload

An export is a **bundle of per-resource files** (JSON). You can either:

- upload one file per resource — the **file name is the resource** (e.g. `customers.json`,
  `prices.json`, `subscriptions.json`); or
- paste / upload a single **combined JSON** document whose top-level keys are the resource names:

```json
{
  "products":      [ … ],
  "prices":        [ … ],
  "coupons":       [ … ],
  "customers":     [ … ],
  "subscriptions": [ … ],
  "invoices":      [ … ]
}
```

Each record inside uses that provider's **native field names** — the adapter maps them. A `{ "data": [ … ] }` envelope (the shape provider list-API dumps use) is also accepted.

### Resources per source

Every adapter's `expectedFiles()` lists the resources it reads and what each provides; the console
renders this under **Supported sources & export files**. In summary:

- **Stripe** — `products`, `prices`, `coupons`, `customers`, `subscriptions`, `invoices`.
- **Chargebee** — `item_families` (products), `plans`, `coupons`, `customers`, `subscriptions`,
  `invoices`.
- **Recurly** — `plans`, `coupons`, `accounts` (customers), `subscriptions`, `invoices`.

A partial export imports what it can — provide only the resources you want to migrate. (Prices are
carried inside the `prices`/`plans` records, so there is no separate prices file for Chargebee or
Recurly.)

## Producing the export

Each provider offers a data export or a list API that emits these objects as JSON. Export the
objects to files named for their resource (or assemble the combined JSON above) and upload. No
API credentials are handled by Cbox Billing on this path — see
[the API-pull seam](api-pull-seam.md) for the credentialed alternative and why it is a seam, not a
shipped client.
