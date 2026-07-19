---
title: Dataset schemas
description: The stable, typed column schema of every exportable dataset — invoices, subscriptions, the raw usage-event stream, MRR movements, credit notes, payments, coupons, dunning, seats, licenses and more.
weight: 10
---

# Dataset schemas

Each dataset has one stable, typed schema — the single source the CSV header, the NDJSON
object keys, and the warehouse table DDL are all derived from. Column **types** map to a
physical warehouse type per dialect (see [warehouse load manifests](warehouse-sinks.md));
money is always an integer count of **minor units** (e.g. øre/cents) paired with its
ISO-4217 currency column, never a lossy float. Timestamps are emitted ISO-8601 UTC.

Every dataset declares:

- a **load mode** — `append` (immutable stream), `upsert` (mutable dimension) or `snapshot`
  (full recompute); see [incremental sync](incremental-sync.md);
- a **cursor** — the monotonic column an incremental sync advances by (distinct from the
  business date axis a user picks a range against);
- a **merge key** — the natural key a warehouse `MERGE` dedupes an upsert on.

### `invoices` — Invoices
Issued invoice headers with money totals, billing period and settlement status.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** issued_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate invoice id (stable merge key). |
| `number` | string | Human-facing invoice number (unique per seller). |
| `organization_id` | string | The billed organization id. |
| `subscription_id` | integer | The subscription this invoice billed, if any. |
| `seller` | string | The issuing seller entity key. |
| `currency` | string | ISO-4217 currency of every amount on this invoice. |
| `subtotal_minor` | integer | Net subtotal in minor units. |
| `tax_minor` | integer | Tax in minor units. |
| `total_minor` | integer | Gross total in minor units. |
| `status` | string | Lifecycle status (draft, open, paid, void, uncollectible). |
| `period_start` | timestamp | Start of the billed service period. |
| `period_end` | timestamp | End of the billed service period. |
| `issued_at` | timestamp | When the invoice was finalized/issued. |
| `due_at` | timestamp | Payment due instant. |
| `paid_at` | timestamp | Settlement instant, if paid. |
| `gateway_reference` | string | Payment gateway reference for the settlement. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `invoice_lines` — Invoice lines
Per-line invoice detail (description, quantity, unit and amount in minor units).
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** — · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate line id (stable merge key). |
| `invoice_id` | integer | Parent invoice id. |
| `description` | string | Line description. |
| `quantity` | integer | Billed quantity. |
| `unit_minor` | integer | Unit price in minor units. |
| `net_minor` | integer | Net line amount in minor units. |
| `amount_minor` | integer | Gross line amount in minor units. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `subscriptions` — Subscriptions
Subscriptions with plan, seat quantity, lifecycle status and period markers.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** created_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate subscription id (stable merge key). |
| `organization_id` | string | The owning organization id. |
| `plan_id` | integer | The current plan id. |
| `status` | string | Engine lifecycle status. |
| `display_standing` | string | Materialized display standing (e.g. past_due). |
| `seats` | integer | Purchased seat quantity (the billed quantity). |
| `cancel_at_period_end` | boolean | Whether it is set to not renew. |
| `current_period_start` | timestamp | Start of the current billing period. |
| `current_period_end` | timestamp | End of the current billing period. |
| `trial_ends_at` | timestamp | Trial end instant, if trialing. |
| `canceled_at` | timestamp | Cancellation instant, if canceled. |
| `paused_at` | timestamp | Pause instant, if paused. |
| `pending_plan_id` | integer | A scheduled plan change target, if any. |
| `pending_effective_at` | timestamp | When a scheduled change takes effect. |
| `test_clock_id` | integer | Bound test clock id (test plane only). |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Subscription creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `customers` — Customers (organizations)
Billing-account organizations with contact, locale, currency and tax registration.
- **Load mode:** upsert · **Cursor:** updated_at (timestamp) · **Date axis:** created_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | string | The organization id (stable merge key). |
| `environment_key` | string | The environment/tenant key, if set. |
| `name` | string | Organization display name. |
| `billing_email` | string | Billing contact email. |
| `locale` | string | Preferred locale (BCP-47). |
| `billing_currency` | string | Chosen billing currency (ISO-4217), if any. |
| `billing_country` | string | Billing country (ISO-3166 alpha-2). |
| `billing_subdivision` | string | Billing subdivision/state. |
| `tax_id` | string | Registered tax id, if provided. |
| `tax_id_validated` | boolean | Whether the tax id was validated. |
| `suspended_at` | timestamp | When the account was suspended, if any. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Account creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `mrr_movements` — MRR movements
Recurring-revenue movements (new/expansion/contraction/churn/reactivation) in minor units.
- **Load mode:** append · **Cursor:** id (id) · **Date axis:** occurred_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate movement id. |
| `subscription_id` | integer | The subscription the movement is attributed to. |
| `organization_id` | string | The owning organization id. |
| `currency` | string | ISO-4217 currency of the MRR figures. |
| `kind` | string | Movement kind (new, expansion, contraction, churn, reactivation). |
| `previous_mrr_minor` | integer | MRR before the movement, in minor units. |
| `new_mrr_minor` | integer | MRR after the movement, in minor units. |
| `delta_mrr_minor` | integer | Signed change (new − previous), in minor units. |
| `occurred_at` | timestamp | When the movement occurred. |
| `created_at` | timestamp | Row creation instant. |

### `revenue_snapshot` — Revenue snapshot
Per-subscription monthly/annual recurring revenue as priced right now (engine-computed).
- **Load mode:** snapshot · **Cursor:** id (id) · **Date axis:** — · **Merge key:** subscription_id
| Column | Type | Description |
|---|---|---|
| `subscription_id` | integer | The subscription the snapshot row is for. |
| `organization_id` | string | The owning organization id. |
| `plan_id` | integer | The current plan id. |
| `status` | string | Engine lifecycle status at snapshot time. |
| `seats` | integer | Purchased seat quantity priced into the amount. |
| `currency` | string | ISO-4217 billing currency of the amounts. |
| `mrr_minor` | integer | Monthly recurring revenue in minor units. |
| `arr_minor` | integer | Annual recurring revenue (MRR × 12) in minor units. |
| `snapshot_at` | timestamp | When this snapshot row was computed. |

### `credit_notes` — Credit notes
Credit notes (refund/adjustment legal records) with net/tax/gross in minor units.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** issued_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate credit-note id (stable merge key). |
| `number` | string | Credit-note number. |
| `invoice_number` | string | The invoice number this note credits. |
| `invoice_id` | integer | The credited invoice id, if linked. |
| `organization_id` | string | The organization id. |
| `seller` | string | The issuing seller entity key. |
| `currency` | string | ISO-4217 currency of the amounts. |
| `net_minor` | integer | Net credited amount in minor units. |
| `tax_minor` | integer | Tax credited in minor units. |
| `gross_minor` | integer | Gross credited amount in minor units. |
| `reason` | string | Reason for the credit note. |
| `kind` | string | Credit-note kind (e.g. refund, adjustment). |
| `issued_at` | timestamp | When the credit note was issued. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `payments` — Payments / receipts
Settled invoices as payment receipts (amount received, gateway reference, settled instant).
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** paid_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | The settled invoice id (stable merge key). |
| `number` | string | The invoice number the payment settled. |
| `organization_id` | string | The paying organization id. |
| `seller` | string | The receiving seller entity key. |
| `currency` | string | ISO-4217 currency of the payment. |
| `amount_minor` | integer | Gross amount received, in minor units. |
| `gateway_reference` | string | Payment gateway reference. |
| `paid_at` | timestamp | Settlement instant. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |

### `coupons` — Coupons
Coupon definitions with discount, duration, redemption caps and applicability.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** created_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate coupon id (stable merge key). |
| `code` | string | The redemption code (stored upper-cased). |
| `name` | string | Human label. |
| `discount_type` | string | percent or fixed_amount. |
| `percent_off` | integer | Percentage off (when percent). |
| `amount_off_minor` | integer | Fixed amount off in minor units (when fixed_amount). |
| `currency` | string | ISO-4217 currency of a fixed amount, if any. |
| `duration` | string | once, repeating or forever. |
| `duration_in_periods` | integer | Number of periods for a repeating coupon. |
| `max_redemptions` | integer | Global redemption cap, if any. |
| `times_redeemed` | integer | Redemptions counted so far. |
| `max_redemptions_per_customer` | integer | Per-customer redemption cap, if any. |
| `redeem_by` | timestamp | Redemption deadline, if any. |
| `applies_to` | string | all or plans. |
| `applies_to_plans` | json | Plan keys the coupon applies to (when scoped). |
| `active` | boolean | Whether the coupon is active. |
| `archived_at` | timestamp | Archive instant, if archived. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `coupon_redemptions` — Coupon redemptions
Per-redemption facts linking a coupon to an organization and subscription.
- **Load mode:** append · **Cursor:** id (id) · **Date axis:** redeemed_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate redemption id. |
| `coupon_id` | integer | The redeemed coupon id. |
| `organization_id` | string | The redeeming organization id. |
| `subscription_id` | integer | The subscription the redemption bound to, if any. |
| `redeemed_at` | timestamp | When the coupon was redeemed. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |

### `dunning` — Dunning / recovery
Smart-retry dunning records with decline classification, schedule and recovery status.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** first_failed_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate dunning-record id (stable merge key). |
| `invoice_id` | integer | The past-due invoice being chased. |
| `organization_id` | string | The delinquent organization id. |
| `subscription_id` | integer | The affected subscription id, if any. |
| `attempts` | integer | Retry attempts made so far. |
| `max_attempts` | integer | The retry ceiling for this record. |
| `status` | string | retrying, recovered, exhausted or stopped. |
| `decline_code` | string | Gateway decline code of the last failure. |
| `decline_category` | string | Classified decline category driving the strategy. |
| `save_offer_key` | string | Save-offer key presented, if any. |
| `save_offer_label` | string | Save-offer label presented, if any. |
| `first_failed_at` | timestamp | When the charge first failed. |
| `next_attempt_at` | timestamp | When the next retry is scheduled. |
| `last_attempt_at` | timestamp | When the last retry ran. |
| `last_reference` | string | Gateway reference of the last attempt. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `seat_assignments` — Seat assignments
Purchased seats assigned to member subjects, with assignment source and instant.
- **Load mode:** upsert · **Cursor:** id (id) · **Date axis:** assigned_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | integer | Surrogate assignment id (stable merge key). |
| `organization_id` | string | The owning organization id. |
| `subject` | string | The member subject holding the seat. |
| `source` | string | Assignment source (manual or auto). |
| `assigned_at` | timestamp | When the seat was assigned. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |
| `created_at` | timestamp | Row creation instant. |
| `updated_at` | timestamp | Row last-change instant. |

### `licenses` — Licenses
Issued on-prem license metadata (plan, entitlements, limits, validity window).
- **Load mode:** append · **Cursor:** issued_at (timestamp) · **Date axis:** issued_at · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `id` | string | The license id (the artifact lid claim). |
| `customer_id` | string | The licensed customer id. |
| `deployment_id` | string | The deployment the license is bound to. |
| `plan` | string | The licensed plan/profile key. |
| `entitlements` | json | The granted capability entitlements. |
| `limits` | json | The enforced numeric limits. |
| `licensed_domain` | string | The domain binding, if any. |
| `issued_at` | timestamp | When the license was minted. |
| `not_before` | timestamp | Validity start instant. |
| `expires_at` | timestamp | Validity end instant. |
| `livemode` | boolean | True for the live plane, false for test/sandbox. |

### `usage_events` — Usage events (raw)
The immutable per-event metering log — the source of truth invoices are computed from.
- **Load mode:** append · **Cursor:** id (id) · **Date axis:** — · **Merge key:** id
| Column | Type | Description |
|---|---|---|
| `event_id` | string | The stable, deduplicated event id. |
| `org` | string | The organization the event was metered for. |
| `meter` | string | The meter key the event counts toward. |
| `service` | string | The emitting service/source. |
| `value` | integer | The metered value (integer units). |
| `unique_key` | string | Distinct-count key (UniqueCount aggregation), if any. |
| `weight` | integer | Per-event multiplier (WeightedSum aggregation). |
| `occurred_at` | timestamp | Event instant, ISO-8601 UTC (from the ms epoch). |
| `occurred_at_ms` | integer | Event instant as the raw millisecond epoch. |
