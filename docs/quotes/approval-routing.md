---
title: Approval routing
description: The deal-desk threshold (amount and discount gates), how a quote is routed to pending_approval or auto-approved, the approval queue, and the distinct quotes:approve permission.
weight: 20
---

# Approval routing

When a rep submits a quote, `App\Billing\Cpq\QuoteApprovalRouter` prices it through the engine and
evaluates the configured deal-desk threshold:

```php
'quotes' => [
    'approval' => [
        // First-invoice gross at or above this (minor units) needs approval. null disables the gate.
        'amount_minor' => env('CBOX_BILLING_QUOTE_APPROVAL_AMOUNT_MINOR', 5_000_00),
        // The largest single line discount % at or above this needs approval. 0 disables the gate.
        'discount_percent' => env('CBOX_BILLING_QUOTE_APPROVAL_DISCOUNT_PERCENT', 25),
    ],
],
```

- **Either gate trips it.** A quote whose first-invoice gross meets the amount floor **or** whose
  largest line discount meets the discount ceiling routes to `pending_approval`.
- **Below both → auto-approved.** The quote goes straight to `approved` (stamped as auto-approved),
  ready to send with no human step.
- Set `amount_minor` to `null` (or the env to `null`) to disable the amount gate; set
  `discount_percent` to `0` to disable the discount gate. Both off → nothing needs approval.

## The queue

Operators holding `quotes:approve` work the **Approval queue** (`/quotes/approvals`): approve
(→ `approved`, ready to send) or reject with a reason (→ back to `draft`, the reason shown to the
rep). Both decisions are recorded on the quote (approver identity + timestamp) and audit-logged as
`quote.approved` / `quote.rejected`.

## The send gate

An above-threshold quote **cannot be sent** until it is approved — the `send` action refuses a
quote that is not `approved`. This is the enforced control that keeps an unapproved discount or a
large deal from reaching a customer.

## Permission separation

`quotes:approve` is a **distinct** slug from `quotes:manage`. A rep with `quotes:manage` can author
and submit but cannot approve; approval requires `quotes:approve` (held by `billing-admin`, not the
day-to-day `billing-operator`). This prevents self-approval of one's own deals.
