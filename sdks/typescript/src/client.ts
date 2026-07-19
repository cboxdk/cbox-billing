/**
 * The Cbox Billing client — the single entry point. Construct it with your deployment's
 * base URL and an API token, then reach the typed resources:
 *
 * ```ts
 * import { CboxBilling } from '@cboxdk/cbox-billing';
 *
 * const client = new CboxBilling({
 *   baseUrl: 'https://billing.example.com/api/v1',
 *   token: process.env.CBOX_BILLING_TOKEN!,
 * });
 *
 * const { subscription } = await client.subscriptions.create({ org: 'org_acme', plan: 'pro', seats: 5 });
 * ```
 */

import { HttpClient, type ClientConfig } from './http.js';
import {
  CheckoutResource,
  EnforcementResource,
  InvoicesResource,
  LicensesResource,
  OrganizationsResource,
  PaymentIntentsResource,
  PaymentMethodsResource,
  PlansResource,
  PortalResource,
  SeatsResource,
  SubscriptionsResource,
  TestClocksResource,
  UsageResource,
} from './resources.js';

export type CboxBillingConfig = ClientConfig;

export class CboxBilling {
  private readonly http: HttpClient;

  /** The metered hot path: leases, usage ingest, reserve/commit, entitlements. */
  readonly enforcement: EnforcementResource;
  /** The sellable catalog. */
  readonly plans: PlansResource;
  /** Billing organizations (idempotent upsert). */
  readonly organizations: OrganizationsResource;
  /** Subscription lifecycle + management depth. */
  readonly subscriptions: SubscriptionsResource;
  /** Purchased + assigned seats. */
  readonly seats: SeatsResource;
  /** Per-meter usage-against-allowance. */
  readonly usage: UsageResource;
  /** Issued invoices (auto-pageable). */
  readonly invoices: InvoicesResource;
  /** Hosted checkout sessions. */
  readonly checkout: CheckoutResource;
  /** Hosted customer-portal sessions. */
  readonly portal: PortalResource;
  /** Embedded setup/payment intents. */
  readonly paymentIntents: PaymentIntentsResource;
  /** Saved payment methods (auto-pageable). */
  readonly paymentMethods: PaymentMethodsResource;
  /** On-prem license issue / renew / revoke / activate. */
  readonly licenses: LicensesResource;
  /** Sandbox test-clock advance. */
  readonly testClocks: TestClocksResource;

  constructor(config: CboxBillingConfig) {
    this.http = new HttpClient(config);
    this.enforcement = new EnforcementResource(this.http);
    this.plans = new PlansResource(this.http);
    this.organizations = new OrganizationsResource(this.http);
    this.subscriptions = new SubscriptionsResource(this.http);
    this.seats = new SeatsResource(this.http);
    this.usage = new UsageResource(this.http);
    this.invoices = new InvoicesResource(this.http);
    this.checkout = new CheckoutResource(this.http);
    this.portal = new PortalResource(this.http);
    this.paymentIntents = new PaymentIntentsResource(this.http);
    this.paymentMethods = new PaymentMethodsResource(this.http);
    this.licenses = new LicensesResource(this.http);
    this.testClocks = new TestClocksResource(this.http);
  }
}
