/**
 * SDK behaviour tests, run against an injected fake `fetch` (no network). They exercise the
 * real transport features the SDK promises: idempotency-key generation on writes, retry
 * with backoff honouring `Retry-After`, auto-paging, and typed error mapping.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  CboxBilling,
  ConflictError,
  ValidationError,
  RateLimitError,
  NotFoundError,
  AuthenticationError,
} from '../src/index.js';

interface Recorded {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

/** Build a fake fetch that replays a queue of responses and records the requests it saw. */
function fakeFetch(
  responses: Array<{ status: number; body?: unknown; headers?: Record<string, string> }>,
): { fetch: typeof fetch; calls: Recorded[] } {
  const calls: Recorded[] = [];
  let i = 0;
  const fn = (async (url: string | URL | Request, init?: RequestInit) => {
    const req = init ?? {};
    const headers: Record<string, string> = {};
    if (req.headers) {
      for (const [k, v] of Object.entries(req.headers as Record<string, string>)) headers[k.toLowerCase()] = v;
    }
    calls.push({
      url: String(url),
      method: req.method ?? 'GET',
      headers,
      body: typeof req.body === 'string' ? JSON.parse(req.body) : req.body,
    });
    const spec = responses[Math.min(i, responses.length - 1)]!;
    i++;
    const respHeaders = new Headers({ 'content-type': 'application/json', ...(spec.headers ?? {}) });
    // 204/304 must have a null body per the Response contract.
    const noBody = spec.status === 204 || spec.status === 304 || spec.body === undefined;
    return new Response(noBody ? null : JSON.stringify(spec.body), {
      status: spec.status,
      headers: respHeaders,
    });
  }) as unknown as typeof fetch;
  return { fetch: fn, calls };
}

function makeClient(fetchImpl: typeof fetch, extra: Partial<ConstructorParameters<typeof CboxBilling>[0]> = {}) {
  return new CboxBilling({
    baseUrl: 'https://billing.test/api/v1',
    token: 'cbl_test',
    fetch: fetchImpl,
    retry: { maxRetries: 3, baseDelayMs: 1, maxDelayMs: 5 },
    idempotencyKeyFactory: () => 'fixed-key-123',
    ...extra,
  });
}

test('a write sends bearer auth + a generated Idempotency-Key', async () => {
  const { fetch, calls } = fakeFetch([{ status: 201, body: { subscription: { plan: 'pro' }, coupon: null, payment_intent: null } }]);
  const client = makeClient(fetch);

  await client.subscriptions.create({ org: 'org_1', plan: 'pro', seats: 5 });

  assert.equal(calls.length, 1);
  assert.equal(calls[0]!.method, 'POST');
  assert.equal(calls[0]!.headers['authorization'], 'Bearer cbl_test');
  assert.equal(calls[0]!.headers['idempotency-key'], 'fixed-key-123');
  assert.deepEqual(calls[0]!.body, { org: 'org_1', plan: 'pro', seats: 5 });
});

test('a caller-supplied idempotency key is used verbatim', async () => {
  const { fetch, calls } = fakeFetch([{ status: 201, body: { id: 'lic_1', customer_id: 'org_1', plan: 'p', entitlements: [], limits: {}, issued_at: '', not_before: '', expires_at: '', key: 'k', deployment_id: null, licensed_domain: null, public_key: null } }]);
  const client = makeClient(fetch);

  await client.licenses.issue({ customer_id: 'org_1', plan: 'ent' }, { idempotencyKey: 'my-own-key' });

  assert.equal(calls[0]!.headers['idempotency-key'], 'my-own-key');
});

test('GET requests carry no Idempotency-Key', async () => {
  const { fetch, calls } = fakeFetch([{ status: 200, body: { currency: 'EUR', data: [] } }]);
  const client = makeClient(fetch);

  await client.plans.list({ currency: 'EUR' });

  assert.equal(calls[0]!.method, 'GET');
  assert.ok(calls[0]!.url.includes('currency=EUR'));
  assert.equal(calls[0]!.headers['idempotency-key'], undefined);
});

test('retries 429 honouring Retry-After, then succeeds', async () => {
  const { fetch, calls } = fakeFetch([
    { status: 429, body: { message: 'Too Many Attempts.' }, headers: { 'retry-after': '0' } },
    { status: 429, body: { message: 'Too Many Attempts.' }, headers: { 'retry-after': '0' } },
    { status: 200, body: { meters: {} } },
  ]);
  const client = makeClient(fetch);

  const res = await client.enforcement.entitlements('org_1');

  assert.equal(calls.length, 3);
  assert.deepEqual(res, { meters: {} });
});

test('gives up after maxRetries and throws a typed RateLimitError', async () => {
  const { fetch, calls } = fakeFetch([{ status: 429, body: { message: 'Too Many Attempts.' }, headers: { 'retry-after': '7' } }]);
  const client = makeClient(fetch);

  await assert.rejects(
    () => client.enforcement.entitlements('org_1'),
    (err: unknown) => {
      assert.ok(err instanceof RateLimitError);
      assert.equal(err.status, 429);
      assert.equal(err.retryAfter, 7);
      return true;
    },
  );
  assert.equal(calls.length, 4); // 1 initial + 3 retries
});

test('retries a 500 but not a 404', async () => {
  const server = fakeFetch([
    { status: 500, body: { error: 'boom' } },
    { status: 200, body: { data: [] } },
  ]);
  const client = makeClient(server.fetch);
  await client.paymentMethods.list('org_1').all();
  assert.equal(server.calls.length, 2);

  const notFound = fakeFetch([{ status: 404, body: { error: 'This organization has no active subscription.' } }]);
  const client2 = makeClient(notFound.fetch);
  await assert.rejects(() => client2.subscriptions.get('org_1'), NotFoundError);
  assert.equal(notFound.calls.length, 1); // 404 is terminal, not retried
});

test('maps 409 and 422 to typed errors with details', async () => {
  const conflict = fakeFetch([{ status: 409, body: { error: 'This Idempotency-Key was already used with a different request payload.' } }]);
  await assert.rejects(() => makeClient(conflict.fetch).seats.setPurchased('org_1', 3), ConflictError);

  const validation = fakeFetch([{ status: 422, body: { message: 'The plan field is required.', errors: { plan: ['The plan field is required.'] } } }]);
  await assert.rejects(
    () => makeClient(validation.fetch).subscriptions.create({ org: 'org_1', plan: '' }),
    (err: unknown) => {
      assert.ok(err instanceof ValidationError);
      assert.deepEqual(err.details, { plan: ['The plan field is required.'] });
      return true;
    },
  );
});

test('maps 401 to AuthenticationError', async () => {
  const { fetch } = fakeFetch([{ status: 401, body: { error: 'Invalid API token.' } }]);
  await assert.rejects(() => makeClient(fetch).usage.summary('org_1'), AuthenticationError);
});

test('auto-pages a {data:[]} list into individual items', async () => {
  const { fetch } = fakeFetch([
    { status: 200, body: { data: [{ number: 'INV-1', amount_minor: 100 }, { number: 'INV-2', amount_minor: 250 }] } },
  ]);
  const client = makeClient(fetch);

  const numbers: string[] = [];
  let total = 0;
  for await (const inv of client.invoices.list('org_1')) {
    numbers.push(inv.number);
    total += inv.amount_minor;
  }

  assert.deepEqual(numbers, ['INV-1', 'INV-2']);
  assert.equal(total, 350);
});

test('reserve outcome narrows on the discriminant', async () => {
  const { fetch } = fakeFetch([{ status: 200, body: { outcome: 'denied', reason: 'allowance_exhausted', upgrade: { required_plan: 'pro', checkout_url: 'https://x/y' } } }]);
  const client = makeClient(fetch);

  const outcome = await client.enforcement.reserve({ org: 'org_1', meters: [{ meter: 'm', estimate: 1 }] });
  assert.equal(outcome.outcome, 'denied');
  if (outcome.outcome === 'denied') {
    assert.equal(outcome.reason, 'allowance_exhausted');
    assert.equal(outcome.upgrade?.required_plan, 'pro');
  }
});

test('a 204 delete resolves to void', async () => {
  const { fetch, calls } = fakeFetch([{ status: 204 }]);
  const client = makeClient(fetch);
  await client.paymentMethods.detach('org_1', 'pm_1');
  assert.equal(calls[0]!.method, 'DELETE');
});
