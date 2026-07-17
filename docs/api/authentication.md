---
title: Authentication
description: How the enforcement and management APIs authenticate — bearer API tokens (operator, per-org, product-scoped), the optional static operator token, per-org scoping, and issuing tokens.
weight: 51
---

# Authentication

The enforcement and management APIs are authenticated by a **bearer token** via the
`api.token` middleware (`AuthenticateApiToken`). Authentication is deny-by-default:
a request with no matching token is rejected (401), and a token scoped to org A
cannot act on org B (403).

```http
Authorization: Bearer <token>
```

## Token kinds

The `ApiTokenAuthenticator` contract resolves a request to an identity. The bound
`DatabaseApiTokenAuthenticator` recognizes:

| Kind | How it authenticates | Scope |
| --- | --- | --- |
| **Static operator token** | Matches `CBOX_BILLING_API_TOKEN` (config, no DB row). | Any org — the simplest single-tenant / bootstrapping auth. |
| **Per-org token** | Matches a row in `api_tokens`. | One organization (or operator-wide if issued without `--org`). |
| **Product-scoped token** | A per-org/operator token bound to one product key. | Only sees and sells that product's catalog. |

Leave `CBOX_BILLING_API_TOKEN` **unset** in multi-tenant deployments and issue
per-org rows instead. The authenticator is a swappable contract either way.

## Per-org scoping

Every management and enforcement controller calls `denyUnlessMayActFor($request,
$org)`: the token's identity must be allowed to act for the target org, or the
request is refused. A product-scoped token additionally refuses another product's
plan (`mayUseProduct`). This is how one tenant's token can never touch another's
data even though both hit the same `/api/v1` surface.

## Issuing a token

```bash
php artisan billing:token "cbox-assistant prod" --org=<org-id> --product=<product-key>
```

- `name` (required) — a recognizable label.
- `--org` — scope to one organization id; omit for an operator-wide token.
- `--product` — bind to one product key (the token only sees/sells that catalog).

The command prints the token **once** — store it securely; it is not recoverable.

## The webhook and activation surfaces are different

Two surfaces do **not** use bearer tokens:

- **Payment webhooks** (`/webhooks/{gateway}`) authenticate by the **gateway
  signature**, not a token. See [Payments & dunning](../concepts/payments-and-dunning.md).
- **License activation** (`/api/v1/license/activate`) is **unauthenticated by
  design** — the opaque deployment id is the credential. See
  [License activation](license-activation.md).

## Related documentation

- [Enforcement API](enforcement.md)
- [Management API](management.md)
- [Configuration → CORS & throttling](../configuration/cors-and-throttling.md)
