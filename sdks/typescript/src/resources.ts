/**
 * Typed resource methods, one class per API area. Each is a thin, typed wrapper over the
 * shared {@link HttpClient} transport; writes are idempotency-keyed by default (pass
 * `{ idempotencyKey }` to control the key, or the transport auto-generates one).
 */

import { HttpClient } from './http.js';
import { AutoPager } from './pagination.js';
import type * as T from './types.js';

export interface WriteOptions {
  /** Use this exact `Idempotency-Key` (so your own retry replays instead of re-applying). */
  idempotencyKey?: string;
  timeoutMs?: number;
  signal?: AbortSignal;
}

function idem(opts?: WriteOptions): { idempotency?: string; timeoutMs?: number; signal?: AbortSignal } {
  return {
    ...(opts?.idempotencyKey !== undefined ? { idempotency: opts.idempotencyKey } : {}),
    ...(opts?.timeoutMs !== undefined ? { timeoutMs: opts.timeoutMs } : {}),
    ...(opts?.signal !== undefined ? { signal: opts.signal } : {}),
  };
}

// ─────────────────────────────── Enforcement ───────────────────────────────
export class EnforcementResource {
  constructor(private readonly http: HttpClient) {}

  /** Lease a slice of an org's remaining allowance for a meter. */
  lease(body: T.LeaseRequest, opts?: WriteOptions): Promise<T.Lease> {
    return this.http.request({ method: 'POST', path: '/leases', body, ...idem(opts) });
  }

  /** Ingest cumulative usage readings (dedup'd by `seq`). */
  ingestUsage(body: T.UsageIngestRequest, opts?: WriteOptions): Promise<T.UsageIngestResult> {
    // Usage ingest is idempotent by construction (cumulative + seq), so no key is needed.
    return this.http.request({ method: 'POST', path: '/usage', body, idempotency: false, ...idem(opts) });
  }

  /** Reserve a set of meter buckets, all-or-nothing. The decision is in `outcome`. */
  reserve(body: T.ReserveRequest, opts?: WriteOptions): Promise<T.ReserveOutcome> {
    return this.http.request({ method: 'POST', path: '/reserve', body, idempotency: false, ...idem(opts) });
  }

  /** Commit a held reservation to actual usage. */
  commit(body: T.CommitRequest, opts?: WriteOptions): Promise<{ ok: true }> {
    return this.http.request({ method: 'POST', path: '/commit', body, idempotency: false, ...idem(opts) });
  }

  /** The org's resolved per-meter policies (the SDK caches these to enforce locally). */
  entitlements(org: string): Promise<T.EntitlementsResponse> {
    return this.http.request({ method: 'GET', path: `/entitlements/${encodeURIComponent(org)}` });
  }
}

// ─────────────────────────────── Plans ───────────────────────────────
export class PlansResource {
  constructor(private readonly http: HttpClient) {}

  /** List the sellable catalog, priced in the caller's account currency (or `params.currency`). */
  async list(params: T.ListPlansParams = {}): Promise<T.PlanList> {
    return this.http.request({
      method: 'GET',
      path: '/plans',
      ...(params.currency !== undefined ? { query: { currency: params.currency } } : {}),
    });
  }
}

// ─────────────────────────────── Organizations ───────────────────────────────
export class OrganizationsResource {
  constructor(private readonly http: HttpClient) {}

  /** Idempotent upsert of a billing organization (the org id is your own tenant key). */
  async upsert(org: string, body: T.OrganizationUpsertRequest, opts?: WriteOptions): Promise<T.Organization> {
    const res = await this.http.request<T.OrganizationResponse>({
      method: 'PUT',
      path: `/organizations/${encodeURIComponent(org)}`,
      body,
      ...idem(opts),
    });
    return res.organization;
  }
}

// ─────────────────────────────── Subscriptions ───────────────────────────────
export class SubscriptionsResource {
  constructor(private readonly http: HttpClient) {}

  /** The org's current subscription. Throws `NotFoundError` when there is none. */
  get(org: string): Promise<T.Subscription> {
    return this.http.request({ method: 'GET', path: `/subscriptions/${encodeURIComponent(org)}` });
  }

  /** Subscribe an org to a plan (optionally with a trial and/or coupon). */
  create(body: T.SubscribeRequest, opts?: WriteOptions): Promise<T.SubscribeResult> {
    return this.http.request({ method: 'POST', path: '/subscriptions', body, ...idem(opts) });
  }

  /** Preview a plan change without applying it. */
  preview(org: string, plan: string): Promise<T.PlanChangePreview> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/preview`,
      body: { plan },
      idempotency: false,
    });
  }

  /** Apply a plan change now (default) or schedule it for period end. */
  change(org: string, body: T.PlanChangeRequest, opts?: WriteOptions): Promise<T.PlanChangeResult> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/change`,
      body,
      ...idem(opts),
    });
  }

  /** Cancel (immediate / period-end / pause-instead-of-cancel). */
  cancel(org: string, body: T.CancelRequest = {}, opts?: WriteOptions): Promise<T.Subscription> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/cancel`,
      body,
      idempotency: false,
      ...idem(opts),
    });
  }

  /** Win-back: resume a pause, undo a scheduled cancel, or reactivate within the window. */
  reactivate(org: string, opts?: WriteOptions): Promise<T.Subscription> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/reactivate`,
      idempotency: false,
      ...idem(opts),
    });
  }

  /** Pause a subscription (suspend access + metering). */
  pause(org: string, opts?: WriteOptions): Promise<T.Subscription> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/pause`,
      idempotency: false,
      ...idem(opts),
    });
  }

  /** Resume a paused subscription. */
  resume(org: string, opts?: WriteOptions): Promise<T.Subscription> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/resume`,
      idempotency: false,
      ...idem(opts),
    });
  }

  /** Change the seat quantity (preview-equals-charge). Pass `{ preview: true }` to price only. */
  changeQuantity(org: string, body: T.QuantityRequest, opts?: WriteOptions): Promise<T.QuantityPreview> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/quantity`,
      body,
      ...idem(opts),
    });
  }

  /** Attach an add-on (or price it with `{ preview: true }`). */
  addAddOn(org: string, body: T.AddOnRequest, opts?: WriteOptions): Promise<T.AddOnResult | T.AddOnPreview> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/addons`,
      body,
      ...idem(opts),
    });
  }

  /** Detach an add-on by key. */
  removeAddOn(org: string, key: string): Promise<T.Subscription> {
    return this.http.request({
      method: 'DELETE',
      path: `/subscriptions/${encodeURIComponent(org)}/addons/${encodeURIComponent(key)}`,
      idempotency: false,
    });
  }
}

// ─────────────────────────────── Seats ───────────────────────────────
export class SeatsResource {
  constructor(private readonly http: HttpClient) {}

  /** The seat breakdown (purchased, Full, Light). */
  get(org: string): Promise<T.SeatBreakdown> {
    return this.http.request({ method: 'GET', path: `/subscriptions/${encodeURIComponent(org)}/seats` });
  }

  /** Set the purchased Full-seat count (buy/release). */
  setPurchased(org: string, seats: number, opts?: WriteOptions): Promise<T.SeatBreakdown> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/seats`,
      body: { seats },
      ...idem(opts),
    });
  }

  /** Assign a free purchased seat to a member. */
  assign(org: string, subject: string): Promise<T.SeatBreakdown> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/seats/assign`,
      body: { subject },
      idempotency: false,
    });
  }

  /** Free a member's seat (they become Light). */
  unassign(org: string, subject: string): Promise<T.SeatBreakdown> {
    return this.http.request({
      method: 'POST',
      path: `/subscriptions/${encodeURIComponent(org)}/seats/unassign`,
      body: { subject },
      idempotency: false,
    });
  }
}

// ─────────────────────────────── Usage summary ───────────────────────────────
export class UsageResource {
  constructor(private readonly http: HttpClient) {}

  /** Per-meter usage-against-allowance for the current billing period. */
  summary(org: string): Promise<T.UsageSummary> {
    return this.http.request({ method: 'GET', path: `/usage/${encodeURIComponent(org)}` });
  }
}

// ─────────────────────────────── Invoices ───────────────────────────────
export class InvoicesResource {
  constructor(private readonly http: HttpClient) {}

  /** The org's issued invoices, newest first — auto-pageable. */
  list(org: string): AutoPager<T.Invoice> {
    return new AutoPager<T.Invoice>(() =>
      this.http.request({ method: 'GET', path: `/invoices/${encodeURIComponent(org)}` }),
    );
  }
}

// ─────────────────────────────── Checkout / Portal ───────────────────────────────
export class CheckoutResource {
  constructor(private readonly http: HttpClient) {}

  /** Open a hosted checkout session; returns the `{ url }` the customer pays at. */
  createSession(body: T.CheckoutSessionRequest, opts?: WriteOptions): Promise<T.HostedSession> {
    return this.http.request({ method: 'POST', path: '/checkout-sessions', body, idempotency: false, ...idem(opts) });
  }
}

export class PortalResource {
  constructor(private readonly http: HttpClient) {}

  /** Open a hosted customer-portal session; returns the `{ url }`. */
  createSession(body: T.PortalSessionRequest, opts?: WriteOptions): Promise<T.HostedSession> {
    return this.http.request({ method: 'POST', path: '/portal-sessions', body, idempotency: false, ...idem(opts) });
  }
}

// ─────────────────────────────── Payment intents / methods ───────────────────────────────
export class PaymentIntentsResource {
  constructor(private readonly http: HttpClient) {}

  /** Create a SetupIntent (save a card off-session). */
  createSetupIntent(org: string, opts?: WriteOptions): Promise<T.GatewayIntent> {
    return this.http.request({ method: 'POST', path: '/setup-intents', body: { org }, idempotency: false, ...idem(opts) });
  }

  /** Create a PaymentIntent (charge on-session) for an invoice or an ad-hoc amount. */
  createPaymentIntent(body: T.PaymentIntentRequest, opts?: WriteOptions): Promise<T.GatewayIntent> {
    return this.http.request({ method: 'POST', path: '/payment-intents', body, idempotency: false, ...idem(opts) });
  }
}

export class PaymentMethodsResource {
  constructor(private readonly http: HttpClient) {}

  /** List an org's saved payment methods (display fields only). Auto-pageable. */
  list(org: string): AutoPager<T.PaymentMethod> {
    return new AutoPager<T.PaymentMethod>(() =>
      this.http.request({ method: 'GET', path: `/payment-methods/${encodeURIComponent(org)}` }),
    );
  }

  /** Make a saved method the off-session default. */
  async setDefault(org: string, id: string): Promise<T.PaymentMethod[]> {
    const res = await this.http.request<T.DataEnvelope<T.PaymentMethod>>({
      method: 'POST',
      path: `/payment-methods/${encodeURIComponent(org)}/default`,
      body: { id },
      idempotency: false,
    });
    return res.data;
  }

  /** Detach a saved method. */
  async detach(org: string, id: string): Promise<void> {
    await this.http.request({
      method: 'DELETE',
      path: `/payment-methods/${encodeURIComponent(org)}/${encodeURIComponent(id)}`,
      idempotency: false,
    });
  }
}

// ─────────────────────────────── Licenses ───────────────────────────────
export class LicensesResource {
  constructor(private readonly http: HttpClient) {}

  /** Mint an on-prem license (operator token only). The `key` is show-once. */
  issue(body: T.LicenseIssueRequest, opts?: WriteOptions): Promise<T.License> {
    return this.http.request({ method: 'POST', path: '/licenses', body, ...idem(opts) });
  }

  /** Renew (reissue with an extended window). */
  renew(id: string, body: T.LicenseRenewRequest = {}, opts?: WriteOptions): Promise<T.License> {
    return this.http.request({
      method: 'POST',
      path: `/licenses/${encodeURIComponent(id)}/renew`,
      body,
      idempotency: false,
      ...idem(opts),
    });
  }

  /** Revoke (add to the signed revocation list). */
  revoke(id: string, body: T.LicenseRevokeRequest = {}, opts?: WriteOptions): Promise<T.LicenseRevokeResult> {
    return this.http.request({
      method: 'POST',
      path: `/licenses/${encodeURIComponent(id)}/revoke`,
      body,
      idempotency: false,
      ...idem(opts),
    });
  }

  /**
   * The unauthenticated activation heartbeat. NOTE: this endpoint takes no bearer token —
   * a self-hosted deployment holds none. Call it with a client whose token is irrelevant;
   * the `deployment_id` query param is the credential.
   */
  activate(deploymentId: string): Promise<T.ActivationBundle> {
    return this.http.request({
      method: 'GET',
      path: '/license/activate',
      query: { deployment_id: deploymentId },
    });
  }
}

// ─────────────────────────────── Test mode ───────────────────────────────
export class TestClocksResource {
  constructor(private readonly http: HttpClient) {}

  /** Fast-forward a sandbox test clock (requires a `cbt_` test-mode token). */
  advance(id: string, target: string, opts?: WriteOptions): Promise<T.TestClockAdvanceResult> {
    return this.http.request({
      method: 'POST',
      path: `/test/clocks/${encodeURIComponent(id)}/advance`,
      body: { target },
      idempotency: false,
      ...idem(opts),
    });
  }
}
