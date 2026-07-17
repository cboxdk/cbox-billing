---
title: License activation
description: The optional, unauthenticated license-activation heartbeat a self-hosted deployment may call to refresh its license and revocation list — why it is unauthenticated, and why offline installs must not depend on it.
weight: 55
---

# License activation

A self-hosted deployment that installs a consume-license can optionally refresh it —
and the revocation list — through the **activation heartbeat**.

```http
GET /api/v1/license/activate
```

## Unauthenticated by design

The heartbeat is **not** bearer-token authenticated, and that is deliberate: a
self-hosted deployment holds no operator token. The **opaque deployment id** is the
credential; an unknown one gets a generic **404** (never a hint that it was close).
The endpoint is rate-limited with an inline `throttle:30,1` so it cannot be probed.

## Offline installs must not depend on it

On-prem licensing is **offline-first**. An air-gapped install verifies its license
artifact locally against the bundled public key and unlocks capabilities with no call
home. The activation heartbeat is a convenience for connected deployments to pull a
renewed artifact or a fresh revocation list — it is **not** required, and offline
installs neither call it nor depend on it.

## Where it fits

| Path | Auth | Purpose |
| --- | --- | --- |
| `POST /api/v1/licenses` (+ `/renew`, `/revoke`) | Operator bearer token | The **issuer** mints/renews/revokes a license. See [Management API](management.md). |
| `GET /api/v1/license/activate` | Deployment id (unauthenticated) | A **downstream deployment** refreshes its own license + revocation list. |

The issuer side (this app minting licenses) and the consume side (a deployment
refreshing its own) are separate flows on separate auth models.

## Related documentation

- [Concepts → On-prem licensing](../concepts/licensing.md)
- [Open core → Capability gating](../open-core/capability-gating.md)
- [Management API](management.md)
