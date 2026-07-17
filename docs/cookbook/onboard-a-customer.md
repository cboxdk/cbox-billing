---
title: Onboard a customer organization
description: Provision a billing organization and subscribe it to a plan through the management API, with per-org token scoping.
weight: 81
---

# Onboard a customer organization

A billing organization maps one-to-one to a Cbox ID identity organization. A merchant
platform provisions the orgs it bills for on demand, then subscribes them.

## 1. Provision the organization (idempotent upsert)

```bash
curl -s -X PUT http://localhost:8000/api/v1/organizations/org_123 \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{ "name": "Acme Inc", "billing_address": { "country": "DK" } }'
```

`PUT /organizations/{org}` is an idempotent upsert — safe to call repeatedly as the
merchant's own records change.

## 2. Subscribe the org to a plan

```bash
curl -s -X POST http://localhost:8000/api/v1/subscriptions \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: subscribe-org_123-team-01" \
  -d '{ "org": "org_123", "plan": "team", "seats": 5, "currency": "EUR" }'
```

Response carries the subscription (`plan`, `status`, `seats`, period bounds). Send an
`Idempotency-Key` so a retried subscribe cannot create two subscriptions.

To open a **free trial** instead, add `"trial": true` (or `"trial_days": 30`). See
[Run a trial to conversion](trial-to-conversion.md).

## 3. Read it back

```bash
curl -s http://localhost:8000/api/v1/subscriptions/org_123 \
  -H "Authorization: Bearer <token>"
```

## Token scoping

Issue a **per-org** token so the customer's own integration can only touch its org:

```bash
php artisan billing:token "acme integration" --org=org_123
```

A token scoped to `org_123` calling for another org gets a 403. A `--product`-scoped
token additionally sees only that product's catalog. See
[API → Authentication](../api/authentication.md).

## Related documentation

- [API → Management](../api/management.md)
- [Identity → Org-level entitlements](../identity/entitlements.md)
