---
title: Quote lifecycle
description: The quote states (draft → pending_approval → approved → sent → accepted, with declined/expired off-ramps), the console list/detail/authoring surfaces, and clone/expire/resend.
weight: 10
---

# Quote lifecycle

A quote (`App\Models\Quote`) moves through the `App\Billing\Cpq\Enums\QuoteStatus` states:

| Status | Meaning |
| --- | --- |
| `draft` | Editable by the rep; not yet submitted. |
| `pending_approval` | Above the deal-desk threshold; waiting on an approver. |
| `approved` | Approved (or auto-approved below threshold); ready to send. |
| `sent` | Out with the customer at the tokenized order-form URL. |
| `accepted` | Accepted by the customer; the subscription is provisioned. |
| `declined` | Declined by the customer (terminal). |
| `expired` | Passed its validity or expired by an operator (terminal). |

Only a `draft` is editable. Only an `approved` quote can be sent. Only a `sent`, unexpired quote is
open to a customer decision.

## The console

- **List** (`/quotes`) — status tabs (All, Drafts, Pending approval, Approved, Sent, Accepted,
  Declined, Expired), search by number / prospect / customer, and pagination.
- **Detail** (`/quotes/{quote}`) — the priced line items, the contract terms, the first-invoice and
  committed-value totals, the approval state, the acceptance record, and a cross-link to the
  provisioned subscription and the customer organization. The lifecycle actions (submit, send,
  resend, expire, clone, delete, and — for an approver — approve/reject) live here.
- **Authoring** (`/quotes/new`, `/quotes/{quote}/edit`) — the header (customer or prospect, selling
  entity, currency, validity, owner, notes, order coupon), the repeatable line items, and the
  contract terms. Totals recompute through the engine when the quote is saved and shown.

## Actions

- **Submit** routes the quote for approval (or auto-approves it below threshold).
- **Send** mints the opaque order-form token and opens the order form.
- **Resend** re-stamps the send timestamp on the same link.
- **Expire** closes an outstanding (approved or sent) quote.
- **Clone** starts a fresh editable draft from any quote (new number; no token/approval/acceptance
  carried over) — the basis for renewals and revisions.
- **Delete** removes a quote that has not yet provisioned a subscription.

Every console mutation is audit-logged (see [Audit & compliance](../audit-compliance/_index.md));
the route → action map lives in `App\Billing\Audit\Enums\AuditAction`.
