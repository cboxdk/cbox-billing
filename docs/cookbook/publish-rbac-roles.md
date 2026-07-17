---
title: Publish RBAC roles to Cbox ID
description: Declare Billing's roles and permissions in code and publish them to Cbox ID with cbox-id:publish-manifest — including the apps.manifest scope prerequisite.
weight: 89
---

# Publish RBAC roles to Cbox ID

Cbox Billing declares its roles/permissions in code and publishes them to Cbox ID,
which then assigns them to users. Background:
[Federated RBAC manifest](../identity/rbac-manifest.md).

## 1. Declare the manifest

Roles and permissions live in `config/cbox-id-client.php` → `authz`. The app ships a
complete catalog and three roles (`billing-admin`, `billing-operator`,
`billing-viewer`). To add or adjust a role, edit that config — each permission is a
`feature:action` key mapping to a real console/API operation:

```php
'roles' => [
    [
        'key' => 'billing-refunder',
        'name' => 'Refund Desk',
        'description' => 'Read subscriptions and issue refunds only.',
        'permissions' => ['subscriptions:read', 'invoices:read', 'invoices:refund'],
    ],
    // …
],
```

## 2. Grant the client the manifest scope

The billing OAuth client must hold the **`apps.manifest`** scope. In the Cbox ID
console: Developers → Apps → your billing app → grant `apps.manifest`. Without it, the
publish call is rejected.

Ensure the issuer + client credentials are set:

```dotenv
CBOX_ID_ISSUER=https://id.acme.com
CBOX_ID_CLIENT_ID=...
CBOX_ID_CLIENT_SECRET=...
```

## 3. Publish

```bash
php artisan cbox-id:publish-manifest
```

Idempotent, and already part of `composer deploy` — so a normal deploy keeps the
manifest current. The declared roles then appear read-only on the Cbox ID Roles page
under "App roles — declared by your apps."

## 4. Assign and enforce

A Cbox ID admin assigns the roles to users. Assigned roles/permissions arrive in the
user's token claims; the app enforces them (e.g. `invoices:refund` gates credit-note
issuance). The app declares the vocabulary and enforces the assignment — it never
invents claims.

## Related documentation

- [Identity → Federated RBAC manifest](../identity/rbac-manifest.md)
- [Deployment → Production checklist](../deployment/production-checklist.md)
