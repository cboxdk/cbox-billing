/**
 * Cbox Billing SDK — a runnable tour of the management API.
 *
 *   CBOX_BILLING_URL=https://billing.example.com/api/v1 \
 *   CBOX_BILLING_TOKEN=cbl_... \
 *   npm run example
 *
 * It provisions an org, subscribes it to a plan with a coupon, previews and applies a plan
 * change, reserves + commits usage, lists invoices with auto-paging, and shows typed error
 * handling for a 409/422.
 */

import {
  CboxBilling,
  ConflictError,
  ValidationError,
  RateLimitError,
  CboxBillingError,
} from '../src/index.js';

const client = new CboxBilling({
  baseUrl: process.env.CBOX_BILLING_URL ?? 'http://localhost/api/v1',
  token: process.env.CBOX_BILLING_TOKEN ?? 'cbl_example',
  // Retry 429/5xx up to 4 times, honouring Retry-After.
  retry: { maxRetries: 4, baseDelayMs: 400, maxDelayMs: 20_000 },
  timeoutMs: 15_000,
});

async function main(): Promise<void> {
  const org = 'org_acme';

  // 1. Provision the billing org (idempotent — safe to repeat on every tenant signup).
  const organization = await client.organizations.upsert(org, {
    name: 'Acme Inc',
    billing_email: 'billing@acme.example',
    billing_country: 'DK',
    billing_currency: 'DKK',
  });
  console.log('org:', organization.id, organization.billing_currency);

  // 2. Show the catalog, then subscribe with a coupon. The write auto-generates an
  //    Idempotency-Key, so a network retry cannot create a duplicate subscription.
  const catalog = await client.plans.list({ currency: 'DKK' });
  console.log('plans:', catalog.data.map((p) => `${p.key} ${p.price.minor}${p.price.currency}`).join(', '));

  try {
    const result = await client.subscriptions.create({ org, plan: 'pro', seats: 5, coupon: 'LAUNCH20' });
    console.log('subscribed:', result.subscription.plan, result.subscription.status);
    if (result.coupon) console.log('coupon saved:', result.coupon.discount_minor, result.coupon.currency);
  } catch (err) {
    if (err instanceof ValidationError) {
      // e.g. an invalid coupon is a 422 with a business message (no `details`).
      console.error('subscribe refused:', err.message, err.details ?? '');
    } else {
      throw err;
    }
  }

  // 3. Preview a plan change, then apply it.
  const preview = await client.subscriptions.preview(org, 'enterprise');
  console.log('change due now:', preview.due_now_minor, 'new recurring:', preview.new_recurring_minor);
  const changed = await client.subscriptions.change(org, { plan: 'enterprise', when: 'now' });
  console.log('changed, scheduled =', changed.scheduled);

  // 4. Enforce usage: reserve, then commit the actuals.
  const outcome = await client.enforcement.reserve({ org, meters: [{ meter: 'api_calls', estimate: 10 }] });
  if (outcome.outcome === 'allowed') {
    await client.enforcement.commit({ reservation_id: outcome.reservation_id, actuals: [{ meter: 'api_calls', actual: 8 }] });
    console.log('committed reservation', outcome.reservation_id);
  } else if (outcome.outcome === 'denied') {
    console.log('denied:', outcome.reason, outcome.upgrade ? `→ upgrade to ${outcome.upgrade.required_plan}` : '');
  } else {
    console.log('indeterminate:', outcome.reason);
  }

  // 5. Auto-page the invoices.
  let total = 0;
  for await (const invoice of client.invoices.list(org)) {
    total += invoice.amount_minor;
  }
  console.log('lifetime invoiced (minor):', total);

  // 6. Typed error handling.
  try {
    // Reusing an add-on key with a conflicting state surfaces as a 409.
    await client.subscriptions.addAddOn(org, { key: 'extra_storage', price_minor: 500, currency: 'DKK' });
  } catch (err) {
    if (err instanceof ConflictError) console.error('conflict:', err.message);
    else if (err instanceof RateLimitError) console.error('rate limited, retry after', err.retryAfter, 's');
    else if (err instanceof CboxBillingError) console.error(`api error ${err.status} (${err.code}):`, err.message);
    else throw err;
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
