---
title: Identity
description: How Cbox Billing signs in against Cbox ID over OIDC, publishes its federated RBAC manifest, and enforces entitlements at the organization level.
weight: 30
---

# Identity

Cbox Billing does not own a user table for its operators. It authenticates them
against a running **Cbox ID** instance as a standard OIDC relying party, and it
declares its own roles and permissions **to** Cbox ID so they can be assigned
centrally. Identity and role assignment live in Cbox ID; this app owns what a role
*means* and enforces it.

## In this section

| Page | What |
| --- | --- |
| [OIDC login](oidc-login.md) | The authorization-code + PKCE sign-in flow, discovery, demo mode, and logout. |
| [Federated RBAC manifest](rbac-manifest.md) | The roles/permissions the app declares in code and publishes with `cbox-id:publish-manifest`, and the flag-gated enforcement gate. |
| [Per-environment tenancy](tenancy.md) | The org's home-environment key, the single-default fallback, and how per-environment separation lights up once Cbox ID emits the `environment` claim. |
| [Org-level entitlements](entitlements.md) | One billing account per identity organization, enforced on the hot path — not by inflating tokens. |

## The division of ownership

| Concern | Owned by |
| --- | --- |
| Users, credentials, MFA, passkeys, sessions | Cbox ID |
| Which user holds which role | Cbox ID (assignment) |
| What a role/permission means | Cbox Billing (this app) |
| Enforcing entitlements per organization | Cbox Billing, on the hot path |

## The client library

The OIDC client and manifest publisher come from
[`cboxdk/laravel-id-client`](https://github.com/cboxdk/laravel-id). The app wires it
in `AppServiceProvider` (the `IdentityProvider` binding) and configures it through
`config/services.php` → `cbox_id` and `config/cbox-id-client.php`.

## Related documentation

- [Configuration → Environment](../configuration/environment.md) — the `CBOX_ID_*` keys.
- Identity client package: <https://github.com/cboxdk/laravel-id>
