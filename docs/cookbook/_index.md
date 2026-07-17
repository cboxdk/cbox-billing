---
title: Cookbook
description: Task-first recipes with real commands and endpoints — onboard a customer, author a tiered plan, meter usage, issue an invoice, configure Stripe, license, dunning, trials, RBAC, seller entities, and analytics.
weight: 80
---

# Cookbook

Task-first recipes. Each uses real commands and endpoints from the app. They assume
you have a running app and an API token (`php artisan billing:token …`) where an API
call is shown — see [API → Authentication](../api/authentication.md).

## Recipes

| Recipe | Task |
| --- | --- |
| [Onboard a customer organization](onboard-a-customer.md) | Provision an org and subscribe it. |
| [Author a tiered plan](author-a-tiered-plan.md) | Add a plan with a graduated/volume/package/stairstep price. |
| [Meter usage on the hot path](meter-usage.md) | Reserve, work, commit against a meter. |
| [Issue an invoice + credit note](invoice-and-credit-note.md) | Generate an invoice and a credit note. |
| [Configure Stripe + verify a webhook](configure-stripe.md) | Bind the Stripe gateway and confirm settlement. |
| [Issue / renew / revoke a license](issue-a-license.md) | Mint an on-prem license and manage its lifecycle. |
| [Set up smart-retry dunning](smart-retry-dunning.md) | Configure and run the failed-charge retry schedule. |
| [Run a trial to conversion](trial-to-conversion.md) | Open a trial and convert it. |
| [Publish RBAC roles to Cbox ID](publish-rbac-roles.md) | Declare and publish the manifest. |
| [Add a seller entity for a jurisdiction](add-a-seller-entity.md) | Register a new selling entity. |
| [Read the analytics](read-analytics.md) | Where MRR/NRR/cohorts come from. |

## Related documentation

- [Concepts](../concepts/_index.md)
- [API](../api/_index.md)
