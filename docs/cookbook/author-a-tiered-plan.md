---
title: Author a tiered plan
description: Add a plan with multi-currency prices, per-meter entitlements, an included-credit grant, and a tiered price schedule (graduated / volume / package / stairstep).
weight: 82
---

# Author a tiered plan

Plans, prices, tiers, entitlements, and credit grants are ordinary models. The
[`CatalogSeeder`](../getting-started/first-run.md) is the worked reference — model
your own plan on it. This recipe shows the shape.

## The models

- `Plan` — `{key, product_id, name, interval, active}`.
- `PlanPrice` — one per currency: `{plan_id, currency, price_minor, pricing_model,
  package_size?}`.
- `PlanPriceTier` — the per-tier schedule for tiered models: `{plan_price_id, up_to,
  unit_minor, flat_minor?, sort_order}`.
- `PlanEntitlement` — one per meter: `{plan_id, meter_id, enabled, allowance,
  multiplier, unlimited, overage}`.
- `PlanCreditGrant` — recurring included credits: `{plan_id, pool, kind, cadence,
  amount, denomination}`.

## A graduated plan (seeder-style)

```php
$plan = Plan::query()->create([
    'product_id' => $product->id, 'key' => 'growth',
    'name' => 'Growth', 'interval' => 'month', 'active' => true,
]);

// List price per currency.
foreach (['DKK' => 149_000, 'EUR' => 19_900, 'USD' => 21_900] as $ccy => $minor) {
    PlanPrice::query()->create([
        'plan_id' => $plan->id, 'currency' => $ccy,
        'price_minor' => $minor, 'pricing_model' => 'graduated',
    ]);
}

// Recurring included credits (the enforceable allowance pool).
PlanCreditGrant::query()->create([
    'plan_id' => $plan->id, 'pool' => Pools::INCLUDED,
    'kind' => GrantKind::Base, 'cadence' => GrantCadence::Monthly,
    'amount' => 300_000, 'denomination' => 'credit',
]);
```

## The tier schedules

Set `pricing_model` on the `PlanPrice`, then add `PlanPriceTier` rows. The base
`price_minor` stays the list amount MRR sums; the tiers are the per-seat schedule it
scales by. `up_to` is the inclusive bound (null = ∞).

| Model | Tier field used | Meaning |
| --- | --- | --- |
| `graduated` | `unit_minor` | Each seat slice at its own tier's rate. |
| `volume` | `unit_minor` | Every seat at the single tier the total lands in. |
| `package` | `flat_minor` + `package_size` | A block price per pack. |
| `stairstep` | `flat_minor` | One flat price for the whole bracket. |

```php
foreach ([
    ['up_to' => 10,   'unit_minor' => 0],
    ['up_to' => 50,   'unit_minor' => 1_300],
    ['up_to' => null, 'unit_minor' => 1_050],
] as $order => $tier) {
    PlanPriceTier::query()->create([
        'plan_price_id' => $price->id, 'sort_order' => $order,
    ] + $tier);
}
```

## Per-meter entitlements

```php
PlanEntitlement::query()->create([
    'plan_id' => $plan->id, 'meter_id' => $meters['api.requests']->id,
    'enabled' => true, 'allowance' => 2_000_000,
    'multiplier' => 0.0003, 'unlimited' => false,
    'overage' => OverageBehaviour::Bill,   // or Block for a hard limit
]);
```

`overage = Bill` charges past the allowance; `Block` hard-stops. Set `unlimited =
true` for no ceiling. See [Catalog & pricing](../concepts/catalog-and-pricing.md).

## Verify in the console

Catalog → Plans & pricing renders the plan, its per-currency prices, and the tier
table.

## Related documentation

- [Concepts → Catalog & pricing](../concepts/catalog-and-pricing.md)
- [Getting started → First run & seed data](../getting-started/first-run.md)
