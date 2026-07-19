---
title: Org-level entitlements
description: Why Cbox Billing enforces entitlements at the organization level on the application hot path — one billing account per identity organization — instead of inflating identity tokens.
weight: 34
---

# Org-level entitlements

Cbox Billing keeps a deliberate separation: **identity tokens carry who you are, not
what you have bought.** Entitlements — what an organization's plan allows and how
much of each meter it may consume — are enforced by the billing app at the
**organization level**, on its own hot path.

## One billing account per identity organization

An organization in Cbox ID maps to exactly one billing account (`Organization`
model) here. The OIDC token carries the `org` claim; the app resolves that to the
billing organization and enforces its subscription's entitlements. This is why the
console's `ConsoleCurrentContext` exposes `organizationId()` from the token's `org`
claim — a plugin can resolve the current org without knowing the auth internals.

## Why not put entitlements in the token?

Because entitlements change on the **billing** cadence, not the **identity**
cadence, and they are consumed on the metered hot path:

- A subscription upgrade, an overage, a paused subscription, or a plan-wide rollout
  changes what an org may do — potentially many times a day. A token minted at login
  would be stale.
- Enforcement happens per metered request (`reserve` / `commit`), sub-millisecond,
  against a leased allowance slice. That belongs to the billing engine's derived
  hot-path balance, not to a JWT.
- Keeping entitlements out of the token keeps identity tokens small and stable, and
  keeps money/allowance truth in the ledger where it can be reconciled.

## Where the enforcement happens

The [enforcement API](../api/enforcement.md) resolves, per organization:

- the subscription's plan → per-meter policy (`enabled`, `allowance`, `overage`,
  `weight`), decorated so each meter's included allowance is sourced from its
  wallet pool balance;
- the reconciled usage against that allowance;
- a three-way outcome (allowed / denied / indeterminate).

A semantic denial can carry an **upgrade offer** — the minimum reachable plan that
grants the blocked meter and a pre-built checkout deep-link — via the `UpgradeGate`.
See [Metering & enforcement](../concepts/metering-and-enforcement.md).

## Role permissions vs org entitlements

Do not confuse the two:

- **RBAC permissions** (from the [manifest](rbac-manifest.md)) govern what an
  **operator** may do in the console/management API (e.g. `subscriptions:manage`).
- **Org entitlements** govern what a **customer organization** may consume under its
  plan (e.g. `api.requests` allowance).

They are enforced at different layers and never conflated.

## Related documentation

- [Concepts → Metering & enforcement](../concepts/metering-and-enforcement.md)
- [API → Enforcement API](../api/enforcement.md)
- [Federated RBAC manifest](rbac-manifest.md)
