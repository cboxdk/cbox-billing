# Cbox Billing — TypeScript SDK

A typed TypeScript client for the **Cbox Billing** management + enforcement API. It wraps
the HTTP contract in [`docs/openapi/cbox-billing.yaml`](../../docs/openapi/cbox-billing.yaml)
with typed methods, typed models, and typed errors — plus the transport concerns you'd
otherwise reimplement per project: automatic idempotency keys, retry-with-backoff that
honours `Retry-After`, auto-paging, and timeouts.

- **Zero runtime dependencies** — uses the platform `fetch`. Works on Node 18+, Deno, Bun,
  Cloudflare Workers, and modern browsers.
- **MIT licensed.**

> This SDK targets the broader **management API** (subscribe, change, invoices, seats,
> licenses, hosted sessions, intents). For the metered enforcement hot path inside a
> Laravel app, `cboxdk/laravel-billing-client` is the purpose-built local enforcer — this
> SDK also exposes those endpoints (`enforcement.*`) for non-PHP callers.

## Install

```bash
npm install @cboxdk/cbox-billing
```

## Quick start

```ts
import { CboxBilling } from '@cboxdk/cbox-billing';

const client = new CboxBilling({
  baseUrl: 'https://billing.example.com/api/v1',
  token: process.env.CBOX_BILLING_TOKEN!, // cbl_… (live) or cbt_… (test/sandbox)
});

// Provision the billing org (idempotent — safe on every tenant signup).
await client.organizations.upsert('org_acme', {
  name: 'Acme Inc',
  billing_currency: 'EUR',
});

// Subscribe to a plan with a coupon. The write auto-generates an Idempotency-Key,
// so a network retry can never create a duplicate subscription.
const { subscription, coupon } = await client.subscriptions.create({
  org: 'org_acme',
  plan: 'pro',
  seats: 5,
  coupon: 'LAUNCH20',
});
console.log(subscription.status, coupon?.discount_minor);
```

## Configuration

```ts
new CboxBilling({
  baseUrl: 'https://billing.example.com/api/v1', // required, include /api/v1
  token: 'cbl_…',                                // required
  timeoutMs: 30_000,                             // per-request timeout (default 30s)
  retry: {
    maxRetries: 3,      // retry 429/5xx/network failures (default 3)
    baseDelayMs: 500,   // exponential base with full jitter (default 500ms)
    maxDelayMs: 30_000, // ceiling for any single backoff, incl. Retry-After (default 30s)
  },
  defaultHeaders: {},                 // extra headers on every request
  fetch: globalThis.fetch,            // inject a custom fetch (tests, proxies)
  idempotencyKeyFactory: () => crypto.randomUUID(), // override key generation
});
```

## Idempotency

Every write (`POST`/`PUT`/`DELETE`) sends an `Idempotency-Key` header by default, so a
retried call replays the first result instead of applying twice. Supply your own key to
make a specific business retry idempotent across process restarts:

```ts
await client.subscriptions.create(
  { org: 'org_acme', plan: 'pro' },
  { idempotencyKey: `subscribe:${signupId}` },
);
```

Reusing a key with a **different** body is a `409` (`ConflictError`) — never a silent
replay.

The metered **enforcement hot path** (`enforcement.lease`, `ingestUsage`, `reserve`, `commit`)
is exempt: those calls send no key by default because they are idempotent by construction or
self-heal (usage ingest dedups on `seq`; a lease is a short-lived grant that expires). You can
still pass an explicit `idempotencyKey` to any of them if your own flow needs it.

## Retries & rate limits

429 and 5xx responses (and transient network failures) are retried with exponential
backoff and full jitter. When the server sends `Retry-After`, the SDK waits that long
(bounded by `maxDelayMs`). After `maxRetries` it throws the typed error, with the server's
`retryAfter` still readable:

```ts
import { RateLimitError } from '@cboxdk/cbox-billing';

try {
  await client.usage.summary('org_acme');
} catch (err) {
  if (err instanceof RateLimitError) console.warn('backoff seconds:', err.retryAfter);
}
```

## Pagination

List endpoints return an `AutoPager` you can iterate directly:

```ts
// Auto-page every invoice.
let total = 0;
for await (const invoice of client.invoices.list('org_acme')) {
  total += invoice.amount_minor;
}

// Or collect them.
const methods = await client.paymentMethods.list('org_acme').all();
```

> Note: the management API currently returns each collection as a single `{ data: [...] }`
> page (no server-side cursor). `AutoPager` is written against a cursor seam, so the same
> iteration code keeps working unchanged if/when the API adds cursor pagination.

## Typed errors

Every failure is a `CboxBillingError` subclass carrying `status`, a machine `code`, the
API `message`, and — for validation failures — per-field `details`:

| Class                 | Status | When |
| --------------------- | ------ | ---- |
| `AuthenticationError` | 401    | Missing/invalid token |
| `PermissionError`     | 403    | Token may not act for the org (or needs operator rights) |
| `NotFoundError`       | 404    | No such resource |
| `ConflictError`       | 409    | Idempotency conflict, or a refused invariant (e.g. seat below assigned) |
| `ValidationError`     | 422    | Field validation (`details`) or a business refusal |
| `RateLimitError`      | 429    | Rate limit exceeded (`retryAfter`) |
| `ConnectionError`     | 0      | Network failure / client timeout |

```ts
import { ValidationError, ConflictError } from '@cboxdk/cbox-billing';

try {
  await client.subscriptions.create({ org: 'org_acme', plan: '' });
} catch (err) {
  if (err instanceof ValidationError) console.error(err.details); // { plan: ['The plan field is required.'] }
  else if (err instanceof ConflictError) console.error('conflict:', err.message);
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
  // A denial may carry a pre-built upgrade deep-link.
  console.log(outcome.reason, outcome.upgrade?.checkout_url);
}
```

## Resources

| Namespace | Endpoints |
| --------- | --------- |
| `client.enforcement` | `lease`, `ingestUsage`, `reserve`, `commit`, `entitlements` |
| `client.entitlements` | `features`, `feature`, `hasFeature` |
| `client.plans` | `list` |
| `client.organizations` | `upsert` |
| `client.subscriptions` | `get`, `create`, `preview`, `change`, `cancel`, `reactivate`, `pause`, `resume`, `changeQuantity`, `addAddOn`, `removeAddOn` |
| `client.seats` | `get`, `setPurchased`, `assign`, `unassign` |
| `client.usage` | `summary` |
| `client.invoices` | `list` |
| `client.checkout` / `client.portal` | `createSession` |
| `client.paymentIntents` | `createSetupIntent`, `createPaymentIntent` |
| `client.paymentMethods` | `list`, `setDefault`, `detach` |
| `client.licenses` | `issue`, `renew`, `revoke`, `activate` |
| `client.testClocks` | `advance` |

### Feature gating

`client.entitlements` is the boolean/config **product-gating** sibling of the metered
`client.enforcement.entitlements`. Resolve the whole set, or gate a single capability
deny-by-default:

```ts
const features = await client.entitlements.features('org_acme'); // whole resolved set
if (await client.entitlements.hasFeature('org_acme', 'sso')) {
  // unlock SSO
}
const check = await client.entitlements.feature('org_acme', 'seats'); // typed value + upgrade offer
```

### Token scope

Most methods act **within the calling token's own org scope**. A few target
operator/console-scoped endpoints that a standard per-tenant integration token cannot call:

- **`client.licenses.*`** — on-prem license issuance/renew/revoke is **operator-authed**.
- **`client.testClocks.advance`** — sandbox only; it is **refused on a live token** (`cbl_…`)
  and works only with a **test-mode token** (`cbt_…`).

Calling a scoped endpoint without the required rights returns `403` (`PermissionError`); calling
the test-clock endpoint with a live token is refused. These operator surfaces are normally
driven from the **console**, not a customer-facing integration — reach for them only when your
service genuinely owns that operator role.

## Development

```bash
npm install
npm run typecheck   # tsc --noEmit, strict + exactOptionalPropertyTypes
npm test            # node:test against an injected fake fetch
npm run build       # emit dist/ with .d.ts declarations
npm run example     # run examples/quickstart.ts against a live deployment
```

The types and methods here mirror the OpenAPI contract at
[`docs/openapi/cbox-billing.yaml`](../../docs/openapi/cbox-billing.yaml); a drift test in
the app keeps that contract in lock-step with the routes.
