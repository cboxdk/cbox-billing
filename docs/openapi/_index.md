---
title: OpenAPI
description: The machine-readable OpenAPI 3.1 contract for the Cbox Billing API, in JSON and YAML, and how to regenerate and serve it.
weight: 55
---

# OpenAPI

The Cbox Billing HTTP API ships a machine-readable **OpenAPI 3.1** contract. It is the
same document the running app serves, checked into the repository so it can be diffed in
review and consumed without a running instance.

## The artefacts

| File | Format | Served at |
| --- | --- | --- |
| [`cbox-billing.yaml`](cbox-billing.yaml) | YAML | `/api/openapi.yaml` |
| [`cbox-billing.json`](cbox-billing.json) | JSON | `/api/openapi.json` |

A self-contained, CSP-safe reference page renders the same contract at `/api/docs`. All
three routes are public and deliberately kept outside the token-authenticated
`/api/v1` group — the contract is not a secret.

## Regenerating

The JSON artefact is generated from the YAML source:

```bash
composer openapi:json
```

Regenerate and commit whenever a route, request body, or response shape changes. A stale
contract is worse than none: it silently misleads every generated client.

## Related

- [API](../api/_index.md) — the prose guide to the same surface: auth models, throttle
  tiers, idempotency, and pagination.
- [API · OpenAPI](../api/openapi.md) — how to generate a client from this contract.
