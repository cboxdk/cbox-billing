---
title: Commercial plugins
description: The five private plugins the cloud composition overlays — reseller billing rollup, ASC 606 revenue recognition, accounting/ERP connectors, advanced tax filing, and marketplace — and what each adds.
weight: 63
---

# Commercial plugins

The private `cbox-billing-cloud` composition overlays five proprietary plugins onto
the open base image. Each is **deny-by-default** (feature-gated + license-gated), so
shipping them all in one image is safe — each stays inert until its capability is
wired and its entitlement unlocks it.

| Plugin | Package | Adds | Gated on |
| --- | --- | --- | --- |
| **Reseller** | `cboxdk/cbox-billing-reseller` | Partner/reseller billing rollup — MRR/ARR, open invoices, and active-sub counts across managed orgs — and a gated Reseller console. | `platform.multi_tenant` |
| **Revenue recognition** | `cboxdk/cbox-billing-revrec` | ASC 606 deferred-revenue recognition — straight-line schedules and a revenue waterfall — and a gated console. | Its capability |
| **Connectors** | `cboxdk/cbox-billing-connectors` | Export billing documents (invoices, payments, credit-notes) to an accounting/ERP system over HTTP/JSON or NDJSON, with an idempotent per-document sync ledger and a gated console. | Its capability |
| **Tax-plus** | `cboxdk/cbox-billing-tax-plus` | Advanced tax **filing** on the open `laravel-tax` engine — HMRC MTD 9-box VAT, EU OSS per-member-state payloads — and a gated console. | Its capability |
| **Marketplace** | `cboxdk/cbox-billing-marketplace` | Marketplace billing concerns. | Its capability |

## How each lights up

A plugin registers its own nav, UI, gates, and migrations through the
[console-kit socket](plugin-model.md) on install — zero edits to the base app. It
then stays inert until:

1. its console **feature** is present (it registers its own), and
2. the **`CapabilityGate`** grants its entitlement from the installed consume-license.

Some plugins also read their own env to bind a driver (they still stay entitlement-
gated). For example, the connectors plugin binds no connector until `BILLING_CONNECTOR`
is set to `http` or `ndjson`:

```dotenv
BILLING_CONNECTOR=http
BILLING_CONNECTOR_HTTP_ENDPOINT=https://erp.example.com/ingest
BILLING_CONNECTOR_HTTP_SECRET=...
```

The reseller and revrec plugins read **no env of their own** — they register their
migrations, read models, and gated console purely via auto-discovery and unlock from
the license/plan.

## The docs boundary

These are private packages; their internals are documented in their own repositories
(not public). This page describes only **what the composition adds** to a Cbox Billing
deployment. The open app's own capabilities are documented throughout these docs; the
plugins extend them without changing them.

## Related documentation

- [The plugin model](plugin-model.md)
- [Capability gating](capability-gating.md)
- [Composition](composition.md)
- [Deployment → Cloud composition](../deployment/cloud-composition.md)
