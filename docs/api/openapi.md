---
title: OpenAPI spec & live reference
description: The OpenAPI 3.1 contract for the enforcement + management API, where to fetch it (YAML + JSON), the self-contained /api/docs reference page, and how the contract is kept in sync with the routes.
weight: 56
---

# OpenAPI spec & live reference

The enforcement and management API is described by a hand-authored **OpenAPI 3.1**
contract. It is the machine-readable source of truth for every `/api/v1` operation —
paths, typed request/response bodies, the real error shapes, security, rate-limit headers,
and realistic examples — and it is served straight from the running app.

## Fetch the contract

| URL | What | Content type |
| --- | --- | --- |
| `GET /api/openapi.yaml` | The spec, YAML (the authored source of truth). | `application/yaml` |
| `GET /api/openapi.json` | The same spec as JSON (generated projection). | `application/json` |
| `GET /api/docs` | A self-contained HTML reference page rendered from the spec. | `text/html` |

All three are **public** (no bearer token) so an integrator can read the contract before
provisioning a token. The docs page is fully self-contained — no external scripts, fonts,
or styles — so it works behind a strict CSP and offline.

The console links to the reference from the **command palette** (⌘K → "Open API reference")
and from **Settings → API tokens**.

## Generate a client

Because the contract is standard OpenAPI 3.1, you can generate a client in any language
from `/api/openapi.yaml`, e.g.:

```bash
# Fetch the live spec
curl -s https://billing.example.com/api/openapi.yaml -o cbox-billing.yaml

# Generate with your tool of choice (example: openapi-generator)
openapi-generator-cli generate -i cbox-billing.yaml -g python -o ./cbox-billing-python
```

A hand-polished, typed **[TypeScript SDK](sdk-typescript.md)** ships in the repo under
`sdks/typescript/` — prefer it for JS/TS callers.

## The source of truth & drift guard

- The YAML at `docs/openapi/cbox-billing.yaml` is authored by hand and is the source of
  truth. The JSON projection at `docs/openapi/cbox-billing.json` is generated from it with
  `composer openapi:json` (a dev-only `symfony/yaml` parser; runtime serving reads the
  files raw, so there is no runtime dependency).
- A test (`tests/Feature/OpenApiSpecTest.php`) fails the build unless:
  - the spec parses as OpenAPI 3.1 and the committed JSON is in sync with the YAML;
  - **every `/api/v1` route is documented, and every documented path is a real route**
    (the drift guard runs both directions — the contract can't silently rot);
  - every operation has an `operationId`, typed responses, and examples;
  - the three serving endpoints answer with the right content types and the docs page is
    self-contained.

So the contract stays accurate to the code by construction: add or change a route without
updating the spec and the suite goes red.

## Coverage

The contract documents **all 36** `/api/v1` operations:

- **Enforcement** — `leases`, `usage`, `reserve`, `commit`, `entitlements`.
- **Management** — plans, organizations, the full subscription lifecycle + depth
  (subscribe / preview / change / cancel / pause / resume / reactivate / quantity /
  add-ons), seats, usage summary, invoices, checkout & portal sessions, setup &
  payment intents, payment methods, and on-prem license issue / renew / revoke.
- **Licensing** — the unauthenticated activation heartbeat.
- **Test mode** — the sandbox test-clock advance.

## Related documentation

- [Authentication](authentication.md)
- [Enforcement API](enforcement.md)
- [Management API](management.md)
- [TypeScript SDK](sdk-typescript.md)
