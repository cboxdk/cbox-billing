---
title: Per-environment tenancy
description: How Cbox Billing is environment-AWARE — the org's home-environment key, the single-default fallback, and how per-environment separation lights up once Cbox ID emits the environment claim.
weight: 35
---

# Per-environment tenancy

Cbox ID is **environment-scoped**: every organization lives inside exactly one
environment (a plane such as *production* or *staging*, keyed by a ULID with a
`slug` / `name`). Cbox Billing is **environment-aware** without breaking
single-environment deployments — the design is additive and backward-compatible.

## The model

| Concern | How Cbox Billing handles it |
| --- | --- |
| Tenant primary key | **Stays the org id.** An org belongs to exactly one environment, so its id is already globally unique — there is **no** breaking composite key. |
| Home environment | Recorded on `organizations.environment_key` — the plane billing groups the org under. |
| Active environment (session) | Resolved from the login's `environment` claim, falling back to the configured default. |

## The additive column

The migration adds a **nullable** `organizations.environment_key` and backfills every
existing row to the single configured default, so nothing changes for a
single-environment deployment. The org id remains the tenant key; `environment_key` is
a grouping/filter dimension, not part of the identity.

## The default fallback

`config('cbox-id-client.environment_default')` (env `CBOX_ID_ENVIRONMENT_DEFAULT`,
default `default`) is the single plane every org groups under until Cbox ID emits an
`environment` claim. The resolver
([`EnvironmentContext`](https://github.com/cboxdk/cbox-billing/blob/main/app/Platform/EnvironmentContext.php))
resolves the active environment from the claim and **falls back to this default** —
mirroring Cbox ID's own host→default fallback, so a host-less single-tenant deploy
Just Works. The active plane is surfaced as a small chip in the console top bar.

## Stamping on login

When a login **carries** an `environment` claim, it is stamped on the org's row the
first time it is seen. If the org already has a recorded environment and the claim
disagrees, the mismatch is **logged and the recorded value kept** — billing never
silently moves a tenant between planes. A login with **no** claim stamps the org to
the configured default.

The principal
([`AuthedUser`](https://github.com/cboxdk/cbox-billing/blob/main/app/Auth/AuthedUser.php))
carries `environment` and `environmentName`, populated from the `environment` /
`environment_name` claims **when present**, and null otherwise.

## The signal dependency

> Per-environment separation lights up once Cbox ID emits the `environment` claim.

Today the claim does not appear in the id_token or userinfo, so every org groups under
the single default and the feature is **inert** — a coordinated Cbox ID release is
required before multiple environments are distinguished. Until then the behaviour is
identical to a single-plane deployment.

## Related documentation

- [OIDC login](oidc-login.md) — the sign-in flow that carries the claim.
- [Federated RBAC manifest](rbac-manifest.md) — the peer defensive claim (`permissions`).
- [Configuration → Environment](../configuration/environment.md) — the `CBOX_ID_*` keys.
