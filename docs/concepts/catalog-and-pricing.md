---
title: Catalog & pricing
description: How Cbox Billing models products, plans, multi-currency prices, per-meter entitlements, and the six pricing models — flat, per-unit, graduated, volume, package, and stairstep.
weight: 41
---

# Catalog & pricing

The catalog is the priced surface a customer subscribes to. Cbox Billing stores it
in its own tables and projects it into the console (Catalog → Products, Plans &
pricing) and the management API (`GET /api/v1/plans`).

## The shape

| Model | Table | Holds |
| --- | --- | --- |
| `Product` | `products` | A named product; its `key` is also the plan **family** key for transitions. |
| `Plan` | `plans` | A plan on a product: `key`, `name`, `interval` (e.g. `month`), `active`. |
| `PlanPrice` | `plan_prices` | The recurring list price per currency (integer minor units), plus a `pricing_model` and `package_size`. |
| `PlanPriceTier` | `plan_price_tiers` | The per-tier schedule (`up_to`, `unit_minor`, `flat_minor`) for tiered models. |
| `PlanEntitlement` | `plan_entitlements` | The per-meter policy: `enabled`, `allowance`, `multiplier` (overage weight), `unlimited`, `overage`. |
| `PlanCreditGrant` | `plan_credit_grants` | A recurring included-credit grant (pool, kind, cadence, amount, denomination). |
| `Meter` | `meters` | A metered dimension: `key`, `name`, `unit`. |

A plan is priced in **multiple currencies** at once (the demo seeds DKK + EUR + USD).
The billing-currency lock (see [Accounts](invoicing-and-tax.md)) fixes which one an
account transacts in on its first finalized invoice.

## The six pricing models

Each `PlanPrice` carries a `pricing_model`. The base `price_minor` is always the
list recurring amount the MRR read model sums; a tiered model adds a per-seat tier
schedule that the price scales by.

| Model | Meaning |
| --- | --- |
| **flat** | A single recurring price, independent of quantity. |
| **per-unit** | A per-unit rate applied to the quantity. |
| **graduated** | Each quantity slice is billed at its own tier's rate (progressive). |
| **volume** | Every unit is billed at the single tier the total quantity lands in. |
| **package** | A block price per pack of `package_size` units. |
| **stairstep** | One flat price for the whole quantity bracket. |

The seeded demo catalog exercises all four tiered models — Team `graduated`,
Business `volume`, Scale `package`, Starter `stairstep` — so the catalog console
renders real tier tables. See [Cookbook → Author a tiered plan](../cookbook/author-a-tiered-plan.md).

## Entitlements per meter

Each plan declares, per meter, a projection-ready policy:

- `enabled` — whether the meter applies at all on this plan (a disabled meter is
  deny-by-default).
- `allowance` — the included quantity (or `unlimited`).
- `multiplier` — the per-unit overage weight (null = no overage price).
- `overage` — the behaviour past the allowance: **`Bill`** (charge overage) or
  **`Block`** (hard-stop).

Included allowances are sourced from the plan's credit **wallet pool** at
enforcement time, not from a hand-authored scalar — see [Wallets & credits](wallets-and-credits.md).

## Plan families and transitions

A product's plans share the product key as their **family**. Plans in the same
family may be switched freely; a move **across** families is deny-by-default and
only permitted along an explicitly declared edge in `config/billing.php` →
`transitions` (each edge is `{from, to, guidance?, carry_over?}`). The app binds a
`FamilyTransitionPolicy` from those edges. The demo catalog is a single family, so
every ladder move is a same-family change. The transition mechanics
(preview-equals-charge proration, credit carry-over/forfeiture) live in the engine —
see [ADR-0010](https://github.com/cboxdk/laravel-billing/tree/main/adr).

## Where the depth lives

The pricing math (tier evaluation, proration, quote composition) is the engine's;
this app stores the catalog rows and projects them. For the engine's catalog and
pricing internals see
<https://github.com/cboxdk/laravel-billing/tree/main/docs> → core-concepts.

## Related documentation

- [Subscriptions & lifecycle](subscriptions-and-lifecycle.md)
- [Wallets & credits](wallets-and-credits.md)
- [Cookbook → Author a tiered plan](../cookbook/author-a-tiered-plan.md)
