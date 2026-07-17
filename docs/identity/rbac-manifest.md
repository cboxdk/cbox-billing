---
title: Federated RBAC manifest
description: The roles and permissions Cbox Billing declares in code and publishes to Cbox ID with cbox-id:publish-manifest — the apps.manifest scope, the capability catalog, and the three built-in roles.
weight: 32
---

# Federated RBAC manifest

Cbox Billing declares its **roles and permissions in code** and publishes them to
Cbox ID. Cbox ID owns identity and role assignment; this app owns what each role
means. Assigned roles then arrive in the token's `roles` / `permissions` claims for
the app to enforce.

## The manifest

The manifest lives in `config/cbox-id-client.php` → `authz`. Every permission is a
`feature:action` key that maps to a real console screen or management-API operation
— nothing decorative.

### Permission catalog

| Permission | Grants |
| --- | --- |
| `invoices:read` | View invoices and credit notes |
| `invoices:refund` | Issue refunds and credit notes |
| `subscriptions:read` | View subscriptions |
| `subscriptions:manage` | Create, change, pause, cancel, reactivate |
| `usage:read` | View metered usage |
| `usage:ingest` | Reserve, commit, record usage on the hot path |
| `catalog:read` | View products, plans, prices, meters |
| `catalog:manage` | Create and edit catalog |
| `customers:read` | View organizations and entitlements |
| `customers:manage` | Provision and edit organizations |
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

## A configuration seam to know

The manifest publish itself does not depend on the redirect value — set
`CBOX_ID_REDIRECT_URI` (documented in `.env.example`, and read by both the sign-in flow
and `config/cbox-id-client.php`) for login. The keys the manifest publish needs are the
issuer and client credentials plus the `apps.manifest` scope.

## Related documentation

- [Cookbook → Publish RBAC roles to Cbox ID](../cookbook/publish-rbac-roles.md)
- [Deployment → Production checklist](../deployment/production-checklist.md)
- [OIDC login](oidc-login.md)
