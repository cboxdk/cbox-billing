---
title: Issue / renew / revoke a license
description: Generate the issuer keypair, define a licensable plan profile, and mint, renew, and revoke a signed on-prem license from the console or the management API.
weight: 86
---

# Issue / renew / revoke a license

Cbox Billing mints signed, offline-verifiable Ed25519 licenses from a licensable plan.
This recipe covers the full issuer lifecycle. Background:
[On-prem licensing](../concepts/licensing.md).

## 1. Generate the issuer keypair (once)

```bash
php artisan billing:license-keygen
```

It prints an Ed25519 keypair and writes nothing to disk. Set:

```dotenv
CBOX_LICENSE_SIGNING_KEY=<base64 private key>   # secret — never commit or log
CBOX_LICENSE_PUBLIC_KEY=<base64 public key>     # safe to share
```

With no signing key, licensing is inert (the app still runs); a mint attempt surfaces
a clear operator error.

## 2. Make the plan licensable

A plan can only be minted if it has a **profile** in `config/billing.php` →
`licensing.profiles` (deny-by-default). Each profile declares the capability
`entitlements` the license unlocks and the quantitative `limits`
(organizations / seats / environments):

```php
'profiles' => [
    'enterprise-onprem' => [
        'entitlements' => [ Capabilities::MULTI_TENANT_PLATFORM, Capabilities::SSO, /* … */ ],
        'limits' => [ 'organizations' => 50, 'seats' => 500, 'environments' => 5 ],
    ],
],
```

The key is the plan id, so an active subscription on that plan can be minted.

## 3. Issue

Console: Licenses → Issue (needs the `licenses:issue` permission). Or the API
(idempotency-keyed):

```bash
curl -s -X POST http://localhost:8000/api/v1/licenses \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -H "Idempotency-Key: lic-org_123-enterprise-01" \
  -d '{ "org": "org_123", "plan": "enterprise-onprem" }'
```

Window sizing: `CBOX_LICENSE_VALIDITY_DAYS` (365) for a manual issue; a
subscription-driven reissue tracks the paid-period end + `CBOX_LICENSE_GRACE_DAYS`
(14).

## 4. Renew

Automatic: `billing:issue-licenses` (daily, 03:30, after renewal) reissues for active
licensable subscriptions so a rolled-over paid period is reflected in the expiry —
idempotent (one active license per deployment). Manual:

```bash
curl -s -X POST http://localhost:8000/api/v1/licenses/<id>/renew \
  -H "Authorization: Bearer <token>"
```

## 5. Revoke

```bash
curl -s -X POST http://localhost:8000/api/v1/licenses/<id>/revoke \
  -H "Authorization: Bearer <token>"
```

Revocation adds the license to the **signed revocation list**. A downstream
deployment picks it up either offline (bundled with its next artifact) or via the
[activation heartbeat](../api/license-activation.md).

## 6. Distribute the public key

Console: Licenses → Distribution shows the public key for air-gapped hand-off. Bundle
it in the downstream deployment so it can verify licenses offline — no call home.

## Related documentation

- [Concepts → On-prem licensing](../concepts/licensing.md)
- [Open core → Capability gating](../open-core/capability-gating.md)
- [API → Management](../api/management.md)
