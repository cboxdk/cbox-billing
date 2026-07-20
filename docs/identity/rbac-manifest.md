---
title: Federated RBAC manifest
description: The roles and permissions Cbox Billing declares in code and publishes to Cbox ID with cbox-id:publish-manifest — the apps.manifest scope, the capability catalog, and the three built-in roles.
weight: 33
---

# Federated RBAC manifest

Cbox Billing declares its **roles and permissions in code** and publishes them to
Cbox ID. Cbox ID owns identity and role assignment; this app owns what each role
means. Assigned roles then arrive in the token's `roles` / `permissions` claims for
the app to enforce.

## The manifest

The manifest lives in `config/cbox-id-client.php` → `authz`. Every permission is a
`feature:action` key that maps to a real console screen or management-API operation
— nothing decorative. Two capabilities gate the **token-authed** surface rather than a
console route and so never appear as `billing.permission:` middleware: `usage:ingest`
(the per-org-scoped reserve/commit/record hot path) and `payments:read` (the read
counterpart that rounds out the `payments` read/manage matrix). They are declared and
role-granted all the same, because they are real capabilities Cbox ID assigns.

### Permission catalog

| Permission | Grants |
| --- | --- |
| `invoices:read` | View invoices and credit notes |
| `invoices:manage` | Create, void, mark-paid, resend invoices |
| `invoices:refund` | Issue refunds and credit notes |
| `subscriptions:read` | View subscriptions |
| `subscriptions:manage` | Create, change, pause, cancel, reactivate |
| `quotes:read` | View sales quotes, contracts and the approval queue |
| `quotes:manage` | Author, send, expire and clone sales quotes |
| `quotes:approve` | Approve or reject quotes above the deal-desk threshold |
| `approvals:decide` | Approve or reject held maker-checker requests (two-person rule) |
| `usage:read` | View metered usage |
| `usage:ingest` | Reserve, commit, record usage on the hot path (token-API scope, not a console route) |
| `catalog:read` | View products, plans, prices, meters |
| `catalog:manage` | Create and edit catalog |
| `customers:read` | View organizations and entitlements |
| `customers:manage` | Provision, edit, suspend, reactivate organizations |
| `wallet:manage` | Grant and debit organization wallet credit |
| `payments:read` | View payment methods and gateway state |
| `payments:manage` | Manage methods, checkout, portal, intents |
| `licenses:read` | View issued on-prem licenses |
| `licenses:issue` | Issue and renew licenses |
| `licenses:revoke` | Revoke licenses |
| `analytics:read` | View revenue, retention, usage analytics |
| `settings:read` | View seller entities, tax, gateways, tokens, webhooks |
| `settings:manage` | Configure the above |

### Built-in roles

| Role | Key | Scope |
| --- | --- | --- |
| Billing Admin | `billing-admin` | Every capability, including catalog and settings configuration. |
| Billing Operator | `billing-operator` | Day-to-day operations (subscriptions, refunds, customers, payments, licenses) without catalog or platform-settings changes. |
| Billing Viewer | `billing-viewer` | Read-only access to billing data and analytics. |

## Publishing

```bash
php artisan cbox-id:publish-manifest
```

This pushes the declared roles + permissions to Cbox ID. It is **idempotent** and
runs as part of `composer deploy` (see [Deployment](../deployment/_index.md)). Once
published, the roles appear read-only on the Cbox ID console Roles page under
"App roles — declared by your apps".

### Prerequisites

- `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_ID`, `CBOX_ID_CLIENT_SECRET` set.
- The billing OAuth client must hold the **`apps.manifest`** scope — grant it in the
  Cbox ID console under Developers → Apps. Without that scope, the publish call is
  rejected.

## The lifecycle

1. You declare roles/permissions in `config/cbox-id-client.php`.
2. `cbox-id:publish-manifest` (on deploy) declares them to Cbox ID.
3. A Cbox ID admin assigns roles to users.
4. Assigned roles/permissions arrive in the user's token claims.
5. The app enforces them.

The app never inflates or invents claims — it declares the vocabulary and enforces
what Cbox ID assigns.

## Enforcement

> **This is the *fine* layer.** Beneath it sits a coarser, always-on boundary: the
> [operator-org gate](console-access.md), which decides *whether* a session may reach the
> console at all (deny-by-default). RBAC refines *which* actions an already-admitted operator
> may perform. The two compose — the coarse gate never depends on the `permissions` claim and
> is enforced today; RBAC lights up when that claim ships.

The console carries a permission gate, the `billing.permission:<feature:action>`
route middleware ([`EnforcePermission`](https://github.com/cboxdk/cbox-billing/blob/main/app/Http/Middleware/EnforcePermission.php)).
Each management/console route declares the slug it needs — `catalog:manage` before
price authoring, `subscriptions:manage` before cancel/reactivate, `invoices:manage`
before create/void/mark-paid/resend (and the narrower `invoices:refund` before a
money-returning refund), `wallet:manage` before a wallet grant/debit, `licenses:issue` /
`licenses:revoke` before issuing/revoking, `analytics:read` before the analytics
screens, `settings:read` before the settings page (and `settings:manage` before
authoring a seller entity or minting/revoking an API token), and the `:read` slug on
every read surface — using the exact slugs from the catalog above.

### The current signal gap

**Cbox ID does not yet emit a `permissions` (or `roles`) claim.** Its token issuer
and userinfo endpoint carry only `sub` / `email` / `name` / `org` / `org_name` today,
so this app has **no per-caller permission signal to enforce against**. The gate is
built to be *correct the day that claim ships* and *safe before then*:

- The principal ([`AuthedUser`](https://github.com/cboxdk/cbox-billing/blob/main/app/Auth/AuthedUser.php))
  reads a `permissions` (and `roles`) claim **when present** — from the id_token or
  userinfo — and is empty until it appears.
- The gate is held behind the **`CBOX_ID_RBAC_ENFORCE`** flag (`config/billing.php` →
  `rbac.enforce`), **default `false`**.

### The flag

| `CBOX_ID_RBAC_ENFORCE` | Behaviour |
| --- | --- |
| `false` (default) | **Inert.** The middleware resolves the principal's permissions onto the request (`cbox.permissions`) but **never blocks** — it can't lock the operator surface out before the claim exists. |
| `true` | **Strict deny-by-default.** A caller needs the exact slug the route declares, or gets `403`; an unauthenticated request gets `401`. |

This is the honest rollout: enforcement lights up **only when Cbox ID emits the
permissions claim AND the operator flips the flag** — a coordinated Cbox ID release,
not something this app can fake. Leave the flag off until then.

## A configuration seam to know

The manifest publish itself does not depend on the redirect value — set
`CBOX_ID_REDIRECT_URI` (documented in `.env.example`, and read by both the sign-in flow
and `config/cbox-id-client.php`) for login. The keys the manifest publish needs are the
issuer and client credentials plus the `apps.manifest` scope.

## Related documentation

- [Cookbook → Publish RBAC roles to Cbox ID](../cookbook/publish-rbac-roles.md)
- [Deployment → Production checklist](../deployment/production-checklist.md)
- [OIDC login](oidc-login.md)
