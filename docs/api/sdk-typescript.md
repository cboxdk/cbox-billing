---
title: TypeScript SDK
description: Quickstart for the typed TypeScript client (sdks/typescript) — install, configure, and the transport features it gives you for free (idempotency keys, retry/backoff, auto-paging, typed errors).
weight: 57
---

# TypeScript SDK

A typed TypeScript client for the management + enforcement API ships in the repo under
[`sdks/typescript/`](https://github.com/cboxdk/cbox-billing/tree/main/sdks/typescript). It
wraps the [OpenAPI contract](openapi.md) with typed methods, typed models, and typed
errors, plus the transport concerns you'd otherwise reimplement: automatic idempotency
keys, retry-with-backoff honouring `Retry-After`, auto-paging, and timeouts. Zero runtime
dependencies (platform `fetch`), MIT.

> For the metered enforcement hot path inside a **Laravel** app, use
> `cboxdk/laravel-billing-client` — the purpose-built local enforcer. This SDK targets the
> broader management API (and exposes the enforcement endpoints for non-PHP callers).

## Install

```bash
npm install @cboxdk/cbox-billing
```

## Quickstart

```ts
import { CboxBilling } from '@cboxdk/cbox-billing';

const client = new CboxBilling({
  baseUrl: 'https://billing.example.com/api/v1',
  token: process.env.CBOX_BILLING_TOKEN!, // cbl_… (live) or cbt_… (test)
});

// Idempotent org upsert — safe on every tenant signup.
await client.organizations.upsert('org_acme', { name: 'Acme Inc', billing_currency: 'EUR' });

// Subscribe (the write auto-generates an Idempotency-Key).
const { subscription } = await client.subscriptions.create({
  org: 'org_acme',
  plan: 'pro',
  seats: 5,
  coupon: 'LAUNCH20',
});
```

## What you get for free

- **Idempotency** — every write sends an `Idempotency-Key`; pass your own to make a
  business retry idempotent across restarts. Reusing a key with a different body is a
  `409`, never a silent replay.
- **Retries** — 429/5xx and transient network failures retry with exponential backoff +
  full jitter, honouring `Retry-After` (bounded by `maxDelayMs`).
- **Auto-paging** — the cursor-paginated list endpoints return an `AutoPager` you can
  `for await` over or `.all()`; it follows the server's `next_cursor` transparently. Pass
  `{ limit }` to size the page, `{ cursor }` to resume from one.
- **Typed errors** — `CboxBillingError` subclasses (`AuthenticationError`,
  `PermissionError`, `NotFoundError`, `ConflictError`, `ValidationError`, `RateLimitError`,
  `ConnectionError`) carrying `status`, `code`, `message`, and per-field `details`.

```ts
import { ValidationError, RateLimitError } from '@cboxdk/cbox-billing';

try {
  for await (const invoice of client.invoices.list('org_acme')) {
    // …auto-paged
  }
} catch (err) {
  if (err instanceof ValidationError) console.error(err.details);
  else if (err instanceof RateLimitError) console.warn('retry after', err.retryAfter, 's');
  else throw err;
}
```

## Reserve / commit usage

```ts
const outcome = await client.enforcement.reserve({
  org: 'org_acme',
  meters: [{ meter: 'api_calls', estimate: 10 }],
});

if (outcome.outcome === 'allowed') {
  await client.enforcement.commit({
    reservation_id: outcome.reservation_id,
    actuals: [{ meter: 'api_calls', actual: 8 }],
  });
} else if (outcome.outcome === 'denied') {
  console.log(outcome.reason, outcome.upgrade?.checkout_url); // pre-built upgrade deep-link
}
```

The full method list, configuration reference, and a runnable example script are in the
SDK's [README](https://github.com/cboxdk/cbox-billing/tree/main/sdks/typescript#readme).

## Other languages

The [OpenAPI spec](openapi.md) is standard 3.1 — generate a client for any language from
`GET /api/openapi.yaml`.

## Related documentation

- [OpenAPI spec & live reference](openapi.md)
- [Authentication](authentication.md)
- [Management API](management.md)
