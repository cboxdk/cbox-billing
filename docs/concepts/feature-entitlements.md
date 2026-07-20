---
title: Feature entitlements
description: The boolean / non-metered product-gating dimension — a feature catalog, plan grants aligned with the on-prem license vocabulary, deny-by-default resolution, org overrides, the two API endpoints, the SDK helpers, and the upgrade bridge.
weight: 44
---

# Feature entitlements

Cbox Billing gates a product two ways, and they are deliberately separate:

- **Metered allowances** — "how much of X may this org use?" — the reserve/commit hot
  path over [meters and plan entitlements](metering-and-enforcement.md).
- **Feature entitlements** — "does this org *have* feature X at all?" — a boolean (or
  typed-config) grant. This page covers the second, additive dimension. It never
  touches the metered path: `/entitlements/{org}` is unchanged; feature resolution is
  the boolean sibling `/entitlements/{org}/features`.

## The feature model: boolean vs config

A **feature** is a stable, slug-keyed capability in the catalog:

| Type | Carries | Example | Resolved value |
| --- | --- | --- | --- |
| `boolean` | Pure on/off | `sso`, `custom_domains`, `priority_support` | `enabled: true/false`, `value: null` |
| `config` | A typed value/limit | `max_projects` (integer), `support_tier` (string) | `enabled: true`, `value: 10` |

A config feature declares a `value_type` (`integer` or `string`); its value is stored
as a string on the grant/override and coerced to the real type on resolution, so the
API and SDK hand back a real `int`/`string`, never a stringly-typed value.

A referenced feature is **archived**, never hard-deleted, so a plan grant or an org
override is never orphaned — mirroring the meter/product archival rule.

## Alignment with the license entitlement vocabulary

A feature `key` is drawn from the **same vocabulary the on-prem license `entitlements`
speak** (`Cbox\License\Capabilities`) wherever a licensable capability exists —
`sso`, `scim`, `saml`, `analytics`, `compliance`, `support`, `platform.multi_tenant`.
That is the point: a **hosted subscription** and a **self-hosted license** gate on the
same names, so a capability check reads identically whether the deployment is billed by
subscription or unlocked by an offline license. Hosted-only features that have no
license equivalent (`custom_domains`, `api_access`, `max_projects`) use their own slugs.

## Plan grants

A plan **grants** a set of features, authored on the plan detail hub alongside the
metered entitlements and credit grants. Each grant is one `(plan, feature)` row
(`plan_features`): `enabled`, plus a config value where the feature carries one. This is
the boolean/config peer of a metered plan entitlement.

## Resolution: deny-by-default, override wins

For an org, `FeatureEntitlements` resolves each feature in three layers:

1. **Baseline** — every feature is `enabled: false` (deny-by-default). A feature nobody
   grants is absent, exactly like a meter with no policy.
2. **Plan grant** — the org's *serving* subscription's plan grant turns it on, carrying
   the plan's config value. "Serving" is the engine's serving set (a trialing, past-due
   or non-renewing org keeps its features; a paused/absent subscription grants none).
3. **Org override** — an `organization_feature_overrides` row is the last word:
   `granted: true` forces it on (with the override's value, or the plan's when null);
   `granted: false` forces it off even when the plan grants it.

Each resolved feature reports its `source` (`plan`, `override`, or `default`) so the
console and API show the provenance of a grant.

Resolution is **request-memoized and invalidated on write** (the same PERF-2 pattern the
metered resolver uses): the serving plan, its grants, the org's overrides and the
feature catalog are read once per request; a write to any of them (or the subscription)
flushes the memo. Clients should cache the resolved set with a short TTL and refresh on a
plan/override change.

## The API

Two org-scoped, token-authenticated reads sit beside the metered `/entitlements/{org}`:

| Endpoint | Returns |
| --- | --- |
| `GET /entitlements/{org}/features` | The whole resolved set: `{features: {key: {type, enabled, value, source, upgrade?}}}` |
| `GET /entitlements/{org}/features/{key}` | A single check: `{key, type, enabled, value, source, upgrade?}` |

Deny-by-default holds at the edge too: a feature nobody grants is reported
`enabled: false` (never omitted), and an **unknown key** resolves to `enabled: false`
rather than a 404 — so a client can gate on any key uniformly.

### TypeScript SDK

```ts
const { features } = await client.entitlements.features('org_acme');
if (features.sso?.enabled) { /* … */ }

// Single typed check + a boolean shortcut:
const check = await client.entitlements.feature('org_acme', 'max_projects'); // { enabled, value: 10, … }
if (await client.entitlements.hasFeature('org_acme', 'sso')) { /* … */ }
```

## Org-level overrides (audit-logged)

From the customer detail page an operator can **grant** a feature the org's plan doesn't,
**revoke** one it does, or **clear** the override to restore the plan-resolved value.
Every override write is recorded to the [tamper-evident operator audit trail](../audit-compliance/audit-trail.md)
with the feature, the direction, and the value/reason — a per-customer entitlement change
is an operator action that must be attributable.

## The upgrade bridge

A missing feature is a gating opportunity, not a dead end. The [enforce→upgrade
bridge](metering-and-enforcement.md) resolves the **minimum offered, reachable plan**
that grants a feature the org lacks (respecting plan families and the transition policy,
priced in the account's currency) and attaches an `upgrade: {required_plan, checkout_url}`
offer — a pre-built hosted-checkout deep-link — to every not-granted feature in the set
and on a single check. An org that already has the feature (by plan or override) carries
no offer; deny-by-default, never a fabricated target.

## Related documentation

- [Metering & enforcement](metering-and-enforcement.md) — the metered sibling and the upgrade bridge.
- [Licensing](licensing.md) — the on-prem license `entitlements` this vocabulary aligns with.
- [API](../api/_index.md) and [OpenAPI](../openapi/_index.md) — the full contract.
