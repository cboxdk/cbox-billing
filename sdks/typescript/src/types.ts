/**
 * Typed models for the Cbox Billing API, authored from the OpenAPI 3.1 contract
 * (docs/openapi/cbox-billing.yaml). Monetary `*_minor` fields are integer minor units of
 * the given ISO-4217 currency; timestamps are ISO-8601 strings.
 */

// ── Shared ──
export type Iso8601 = string;
export type CurrencyCode = string;

export interface Money {
  minor: number;
  currency: CurrencyCode;
}

/** The list envelope a mutation returns (the full set, not paginated — e.g. set-default). */
export interface DataEnvelope<T> {
  data: T[];
}

/**
 * The cursor-pagination envelope every collection endpoint returns: one page of `data` plus
 * the opaque `next_cursor` (echo it back as `?cursor=` for the next page; `null`/absent on the
 * last page). `AutoPager` follows it transparently.
 */
export interface PageEnvelope<T> {
  data: T[];
  has_more?: boolean;
  next_cursor?: string | null;
}

/** Per-page controls accepted by the cursor-paginated list endpoints. */
export interface PageParams {
  cursor?: string;
  limit?: number;
}

// ── Enforcement ──
export interface LeaseRequest {
  org: string;
  meter: string;
  size: number;
}

export interface Lease {
  lease_id: string;
  granted: number;
  expires_at: Iso8601;
}

export interface UsageReading {
  meter: string;
  cumulative: number;
  seq: number;
}

export interface UsageIngestRequest {
  org: string;
  entries: UsageReading[];
}

export interface UsageIngestResult {
  ok: true;
  accepted: number;
}

export interface BucketRequest {
  meter: string;
  estimate: number;
}

export interface ReserveRequest {
  org: string;
  meters: BucketRequest[];
}

export interface UpgradeOffer {
  required_plan: string;
  checkout_url: string | null;
}

export type ReserveOutcome =
  | { outcome: 'allowed'; reservation_id: string }
  | { outcome: 'denied'; reason: string; upgrade?: UpgradeOffer }
  | { outcome: 'indeterminate'; reason: string };

export interface CommitActual {
  meter: string;
  actual: number;
}

export interface CommitRequest {
  reservation_id: string;
  actuals: CommitActual[];
}

export interface Entitlement {
  enabled: boolean;
  allowance: number | null;
  weight: number | null;
  overage: string;
  upgrade?: UpgradeOffer;
}

export interface EntitlementsResponse {
  meters: Record<string, Entitlement>;
}

// ── Plans ──
export interface Plan {
  key: string;
  name: string;
  interval: string;
  entitlements: Record<string, Entitlement>;
  price: Money;
}

export interface PlanList {
  currency: CurrencyCode;
  data: Plan[];
  has_more?: boolean;
  next_cursor?: string | null;
}

export interface ListPlansParams {
  currency?: CurrencyCode;
}

// ── Organizations ──
export interface OrganizationUpsertRequest {
  name: string;
  billing_email?: string | null;
  billing_country?: string | null;
  billing_currency?: string | null;
}

export interface Organization {
  id: string;
  name: string;
  billing_email: string | null;
  billing_country: string | null;
  billing_currency: string | null;
}

export interface OrganizationResponse {
  organization: Organization;
}

// ── Subscriptions ──
export interface SubscribeRequest {
  org: string;
  plan: string;
  seats?: number;
  currency?: CurrencyCode;
  trial?: boolean;
  trial_days?: number;
  coupon?: string | null;
}

export interface PendingChange {
  plan: string | null;
  effective_at: Iso8601 | null;
}

export interface AddOn {
  key: string;
  price_minor: number;
  currency: CurrencyCode;
  alignment: 'aligned' | 'independent';
  credit_allotment: number;
}

export interface Subscription {
  plan: string | null;
  status: string;
  paused: boolean;
  seats: number;
  period_start: Iso8601 | null;
  period_end: Iso8601 | null;
  renews_at: Iso8601 | null;
  trial_ends_at: Iso8601 | null;
  canceled_at: Iso8601 | null;
  pending_change: PendingChange | null;
  add_ons: AddOn[];
}

export interface Coupon {
  code: string;
  duration: string;
  currency: CurrencyCode;
  recurring_minor: number;
  discount_minor: number;
  discounted_minor: number;
}

export interface SubscribeResult {
  subscription: Subscription;
  coupon: Coupon | null;
  payment_intent: unknown | null;
}

export interface CreditDelta {
  forfeited: number;
  granted: number;
  carried: number;
}

export interface ProrationLine {
  description: string;
  minor: number;
}

export interface PlanChangePreview {
  due_now_minor: number;
  credit_minor: number;
  new_recurring_minor: number;
  effective_at: Iso8601;
  credit_delta: CreditDelta;
  lines: ProrationLine[];
}

export type PlanChangeResult = PlanChangePreview & {
  scheduled: boolean;
  effective_at: Iso8601 | null;
};

export interface PlanChangeRequest {
  plan: string;
  when?: 'now' | 'period_end';
}

export interface CancelRequest {
  mode?: 'immediate' | 'period_end' | 'pause';
  at_period_end?: boolean;
  reason?: string | null;
  feedback?: string | null;
}

export interface QuantityRequest {
  seats: number;
  preview?: boolean;
}

export interface QuantityPreview {
  applied: boolean;
  from_seats: number;
  to_seats: number;
  due_now_minor: number;
  credit_minor: number;
  currency: CurrencyCode;
}

export interface AddOnRequest {
  key: string;
  price_minor: number;
  currency: CurrencyCode;
  alignment?: 'aligned' | 'independent';
  credit_allotment?: number;
  anchor_day?: number;
  anchor_month?: number;
  interval?: 'monthly' | 'yearly';
  preview?: boolean;
}

export interface AddOnPreview {
  due_now_minor: number;
  currency: CurrencyCode;
  [key: string]: unknown;
}

export interface AddOnResult {
  preview: AddOnPreview;
  add_on: AddOn;
}

// ── Seats ──
export interface SeatBreakdown {
  purchased: number;
  assigned: number;
  free: number;
  full_count: number;
  light_count: number;
  full: string[];
  light: string[];
  types: unknown[];
}

// ── Usage / Invoices ──
export interface UsageMeterSummary {
  used: number;
  allowance: number | null;
  overage: number;
  projected: number;
  projected_overage: number;
}

export interface UsageSummary {
  period: {
    start: Iso8601;
    end: Iso8601;
    elapsed_fraction: number;
  };
  meters: Record<string, UsageMeterSummary>;
}

export interface Invoice {
  number: string;
  date: Iso8601 | null;
  amount_minor: number;
  currency: CurrencyCode;
  status: string;
}

// ── Checkout / Portal ──
export interface CheckoutSessionRequest {
  org: string;
  plan: string;
  return_url: string;
  currency?: CurrencyCode;
  coupon?: string | null;
}

export interface PortalSessionRequest {
  org: string;
  return_url: string;
}

export interface HostedSession {
  url: string;
  expires_at: Iso8601;
}

// ── Intents / Payment methods ──
export interface GatewayIntent {
  gateway: string;
  publishable_key: string | null;
  client_secret: string | null;
  status: string;
  reference: string;
}

export type PaymentIntentRequest =
  | { org: string; invoice: string }
  | { org: string; amount: number; currency: CurrencyCode };

export interface PaymentMethod {
  id: string;
  brand: string;
  last4: string;
  exp_month: number | null;
  exp_year: number | null;
  is_default: boolean;
}

// ── Licenses ──
export interface LicenseIssueRequest {
  customer_id: string;
  plan: string;
  deployment_id?: string;
  licensed_domain?: string;
  expires_at?: Iso8601;
}

export interface LicenseRenewRequest {
  expires_at?: Iso8601;
}

export interface LicenseRevokeRequest {
  reason?: string;
}

export interface License {
  id: string;
  customer_id: string;
  deployment_id: string | null;
  plan: string;
  entitlements: string[];
  limits: Record<string, unknown>;
  licensed_domain: string | null;
  issued_at: Iso8601;
  not_before: Iso8601;
  expires_at: Iso8601;
  key: string | null;
  public_key: string | null;
}

export interface LicenseRevokeResult {
  id: string;
  revoked: true;
}

export interface ActivationBundle {
  deployment_id: string;
  license_id: string;
  license_key: string;
  expires_at: Iso8601;
  revocation_list: string;
  public_key: string | null;
}

// ── Test mode ──
export interface TestClockAdvanceRequest {
  target: Iso8601;
}

export interface TestClockAdvanceResult {
  clock: { id: string; name: string; now_at: Iso8601 };
  advanced_from: Iso8601;
  renewals: number;
  trial_conversions: number;
  dunning_attempts: number;
  invoices: number;
}
