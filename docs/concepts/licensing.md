---
title: On-prem licensing
description: How Cbox Billing mints signed, offline-verifiable Ed25519 licenses from a licensable plan, renews them on subscription renewal, revokes them via a signed list, and offers an optional activation heartbeat.
weight: 47
---

# On-prem licensing

Cbox Billing is a license **issuer**. It mints a signed, offline-verifiable license
from a licensable plan so a self-hosted downstream deployment (e.g. a self-hosted
Cbox ID) can unlock capabilities with **no call home** — it bundles the public key
and verifies the artifact locally.

The crypto core is [`cboxdk/license`](https://github.com/cboxdk/license) (Ed25519);
the app wires the key holders and durable stores in `LicensingServiceProvider`.

## Two distinct keys

The single most important distinction in licensing:

| Key | Role |
| --- | --- |
| **Issuer keypair** (`CBOX_LICENSE_SIGNING_KEY` / `CBOX_LICENSE_PUBLIC_KEY`) | This app signs licenses **for customers** with the private key; downstream deployments verify with the public key. |
| **Consume-license** (`CBOX_BILLING_LICENSE_KEY`) | The license **this deployment installs** to unlock its own bundled commercial plugins. See [Open core → Capability gating](../open-core/capability-gating.md). |

They are separate concerns and separate keys. The signing key is a **secret** —
never committed, never logged; only the public key is ever displayed (in the
Licenses → Distribution panel, for air-gapped hand-off).

## Generating the issuer keypair

```bash
php artisan billing:license-keygen
```

Prints an Ed25519 keypair (never writes keys to disk). Paste the private key into
`CBOX_LICENSE_SIGNING_KEY` (your real `.env` is gitignored) and the public key into
`CBOX_LICENSE_PUBLIC_KEY`. With no signing key, licensing is **inert**: the app boots
and runs everything else, and only an actual mint/revoke surfaces a clear operator
error (the binding is lazy).

## Licensable plans (profiles)

`config/billing.php` → `licensing.profiles` is the issuer-side policy that turns a
paid plan into a license's contents. It is **deny-by-default**: a plan absent here is
not licensable and can never be minted (a self-serve plan ships no offline artifact).
Each profile, keyed by plan id, declares:

- **`entitlements`** — the opaque capability keys the license unlocks (e.g.
  multi-tenant platform, SSO, SAML, SCIM, analytics, compliance, support).
- **`limits`** — quantitative ceilings (organizations / seats / environments; omit
  or null a dimension for "unlimited").

The seeded profiles are `enterprise-onprem` and `team-onprem`.

## Issue, renew, revoke

Durable stores (`DatabaseIssuedLicenseStore`, `DatabaseRevocationRegistry`) keep
minted licenses and revocations across restarts.

- **Issue** — mint a license for a customer + licensable plan. Console:
  Licenses → Issue; API: `POST /api/v1/licenses` (idempotency-keyed).
- **Renew** — reissue with an extended window. The scheduled `billing:issue-licenses`
  pass (daily, 03:30, after renewal) reissues for active subscriptions on a
  licensable plan so a rolled-over paid period is reflected in the expiry. It is
  idempotent (one active license per deployment), so only a period roll-over triggers
  a reissue.
- **Revoke** — add the license to the signed revocation list. API:
  `POST /api/v1/licenses/{id}/revoke`.

Window sizing: `validity_days` (365) for a console/API issue not derived from a
subscription; a subscription-driven reissue tracks the paid-period end plus
`grace_days` (14). `clock_skew_seconds` (60) tolerates air-gapped clock drift.

## Offline verification and the activation heartbeat

Offline installs verify the artifact locally against the public key and **must not**
depend on any call home. Optionally, a deployment may call the **activation
heartbeat** (`GET /api/v1/license/activate`) to refresh its license + revocation
list. It is **unauthenticated by design** — a self-hosted deployment holds no
operator token, so the opaque deployment id is the credential and an unknown one
gets a generic 404. It is rate-limited so it cannot be probed. See
[API → License activation](../api/license-activation.md).

## Related documentation

- [Open core → Capability gating](../open-core/capability-gating.md)
- [Cookbook → Issue, renew, revoke a license](../cookbook/issue-a-license.md)
- License crypto: <https://github.com/cboxdk/license>
