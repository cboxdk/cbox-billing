---
title: Wallets & credits
description: The organization credit wallet — the unified pool-grant model, cadence grants, and how a plan's included allowances are sourced from the wallet at enforcement time.
weight: 44
---

# Wallets & credits

Each organization has a durable **credit wallet**. Under the engine's unified
pool-grant model, a plan's included allowances and credits are one pool burned down
in a single order, so credit balances must survive a restart.

## The durable wallet

`config/billing.php` → `wallet.store` is `database` (the app default), binding the
engine's `DatabaseWallet` — one row per grant lot. The alternative `memory` store is
zero-config for tests only. Credit balances therefore persist across restarts, which
is required because they represent owed value.

## Grants

A plan grants credit through `PlanCreditGrant` rows. The seeded catalog gives each
plan a recurring **included** grant:

- **pool** — `included` (the exempt allowance pool).
- **kind** — e.g. `Base`.
- **cadence** — e.g. `Monthly` (granted per cycle as it vests).
- **amount** / **denomination** — the granted quantity and its unit (e.g. `credit`).

The cycle-renewal pass grants each subscription's recurring allotments as they vest,
idempotently and time-keyed (so a daily cadence drips finer-grained allotments and
never double-grants). See [Subscriptions & lifecycle](subscriptions-and-lifecycle.md).

## Included allowance is sourced from the wallet

This is the key integration point in the app: the meter-policy resolver
(`SubscriptionMeterPolicyResolver`) is **decorated** by the engine's
`WalletIncludedAllowanceResolver` so that each meter's included allowance is read
from its `included`-pool wallet balance rather than a hand-authored scalar. The
wallet is the home of the exempt size, and enforcement reserves against it. This
means:

- A grant vesting increases the enforceable allowance.
- Burn-down reduces it in the same order the engine defines.
- The hot path (`reserve`/`commit`) sees the live wallet-derived allowance.

## The behaviour matrix, lots, and forfeiture

The rules that make credits correct — the credit-pool behaviour matrix, credit lots,
expiry, and forfeiture on plan transition — are the engine's, recorded in
[ADR-0001](https://github.com/cboxdk/laravel-billing/tree/main/adr) and
[ADR-0006](https://github.com/cboxdk/laravel-billing/tree/main/adr). The app stores
the grants and reads the derived balance; it does not reimplement the matrix. A plan
change's credit delta (`forfeited` / `granted` / `carried`) is surfaced in the
change preview — see [Subscriptions & lifecycle](subscriptions-and-lifecycle.md).

## Related documentation

- [Metering & enforcement](metering-and-enforcement.md)
- [Catalog & pricing](catalog-and-pricing.md)
- [Cookbook → Meter usage on the hot path](../cookbook/meter-usage.md)
- Engine wallets: <https://github.com/cboxdk/laravel-billing/tree/main/docs>
