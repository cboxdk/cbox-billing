---
title: Provider-console access
description: The coarse operator-org authorization boundary (SEC-1) that gates the whole provider console — deny-by-default, independent of and beneath the flag-gated per-permission RBAC.
weight: 32
---

# Provider-console access

Cbox ID is a **live, multi-tenant identity provider**. The same issuer that authenticates
the host's internal operators also holds customer and end-user accounts. So "completed OIDC
against Cbox ID" must **never** mean "may administer the billing provider". The provider
console needs an authorization boundary of its own, above authentication.

That boundary is the **operator-org allowlist**: only a session whose identity belongs to an
allowlisted operator organization (or an explicitly allowlisted subject) may reach **any**
console route.

## Two layers, composed

Console access is decided by two independent layers. The coarse one is always on; the fine
one is opt-in and lights up later.

| Layer | What it decides | Config | Default |
| --- | --- | --- | --- |
| **Operator-org gate** (this page) | *Whether* a session may touch the console at all. | `console.operator_orgs` / `console.operator_subjects` | **Deny-by-default** (fail-closed) |
| **Per-permission RBAC** ([manifest](rbac-manifest.md)) | *Which* actions an admitted operator may perform. | `CBOX_ID_RBAC_ENFORCE` + the `permissions` claim | Inert until Cbox ID emits `permissions` |

The operator-org gate is **coarser than and orthogonal to** RBAC. It does not replace it: once
Cbox ID emits a `permissions` claim and the operator flips `CBOX_ID_RBAC_ENFORCE`, RBAC refines
access **within** the operator org. The coarse gate keeps standing guard regardless — even with
RBAC off (its state today), a non-operator session never reaches the console.

## How it is enforced

Every console route sits behind the `auth.cbox` group, and the
[`EnsureOperator`](https://github.com/cboxdk/cbox-billing/blob/main/app/Http/Middleware/EnsureOperator.php)
middleware runs immediately after
[`EnsureAuthenticated`](https://github.com/cboxdk/cbox-billing/blob/main/app/Http/Middleware/EnsureAuthenticated.php)
on that group:

```php
Route::middleware(['auth.cbox', 'billing.operator'])->group(/* the whole console */);
```

1. `auth.cbox` bounces a **guest** to the sign-in screen (remembering the intended URL).
2. `billing.operator` then requires the authenticated principal's `org` to be in
   `console.operator_orgs`, **or** its `sub` to be in `console.operator_subjects`. Otherwise it
   returns a clean **403 "not authorized for this console"** page — never a redirect back to
   login (the session is already valid, so a redirect would loop).

The same boundary backs `ConsoleCurrentContext::isAdmin()`, so a plugin resolving the current
context sees `isAdmin() === true` only for a real operator, not for any authenticated session.

The management/enforcement **API** (`/api/v1/*`, token-authenticated and org-scoped) and the
customer **portal** (`/billing/*`, signed-token) carry their own correct scoping and are
deliberately **not** gated by this boundary.

## Deny-by-default

When **both** allowlists are empty the console is **fail-closed**: every session is denied, and
`EnsureOperator` logs an actionable warning telling the operator to set
`CBOX_BILLING_OPERATOR_ORGS`. There is no implicit bypass — not even demo mode.

## Configuration

```dotenv
# Comma-separated Cbox ID operator organization id(s). Your internal `cbox` operator tenant.
CBOX_BILLING_OPERATOR_ORGS=org_01yourcboxoperatortenant

# Optional break-glass: individually allowlisted Cbox ID subject(s), admitted regardless of org.
CBOX_BILLING_OPERATOR_SUBJECTS=
```

| Env var | config/billing.php | Meaning |
| --- | --- | --- |
| `CBOX_BILLING_OPERATOR_ORGS` | `console.operator_orgs` | Org ids whose members may operate the console. |
| `CBOX_BILLING_OPERATOR_SUBJECTS` | `console.operator_subjects` | Individually allowlisted subjects (break-glass). |

**Local / demo mode.** With no `CBOX_ID_ISSUER`, the app offers a demo sign-in whose principal
carries org `01demo0org0systems`. To reach the console locally, allowlist that org:

```dotenv
CBOX_BILLING_OPERATOR_ORGS=01demo0org0systems
```

## API tokens: the takeover vector, closed

An operator-scoped (org-null) API token acts for **any** org — the cross-tenant takeover vector
this boundary exists to close. Minting one lives at Settings → API tokens, inside the console
group, so it is now reachable **only** by a verified operator. The mint additionally records the
minting operator's Cbox ID subject (`api_tokens.created_by_sub`) so an operator-scoped token is
attributable, not anonymous.

## Related documentation

- [Federated RBAC manifest](rbac-manifest.md) — the fine per-permission layer beneath this gate.
- [OIDC login](oidc-login.md) — the authentication the boundary sits above.
- [Deployment → Production checklist](../deployment/production-checklist.md)
