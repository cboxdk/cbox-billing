---
title: CPQ — sales quoting & contracts
description: The sales-led motion — a rep authors a quote with line items and contract terms, it is threshold-routed for approval, sent to the customer as a branded hosted order form, accepted by e-signature-by-acceptance, and provisions a subscription through the engine.
weight: 67
---

# CPQ — sales quoting & contracts

The **Quotes** area turns the billing engine into a sales-led motion. A sales rep authors a
quote — plan and one-off line items, per-line discounts, and the **contract terms** (length,
billing interval, minimum commitment, price ramp) — and the platform prices it through the same
engine that will bill it, so the numbers on the quote are exactly what the subscription charges
(*preview == charge*). Above a configurable deal-desk threshold the quote routes for **approval**;
once approved it is **sent** to the customer as a seller-branded, self-contained **order form** at
a tokenized URL. The customer **accepts** it with an e-signature-by-acceptance (typed name +
explicit agreement), which records an immutable acceptance and **provisions the subscription**
idempotently.

This is distinct from the engine's internal `Quote` value object (a single confirmable price for a
checkout/change): the CPQ `Quote` is a durable, rep-authored **contract of record** with its own
lifecycle.

## The lifecycle at a glance

```
draft ──submit──▶ pending_approval ──approve──▶ approved ──send──▶ sent ──accept──▶ accepted
  │                     │                                              │
  │                     └──reject──▶ draft                            └──decline──▶ declined
  └──(below threshold, auto-approved)──▶ approved                      (approved|sent) ──expire──▶ expired
```

## In this section

- **[Quote lifecycle](lifecycle.md)** — the states, the console (list, detail, authoring, clone),
  and how status moves through the flow.
- **[Approval routing](approval-routing.md)** — the deal-desk threshold, the approve/reject queue,
  and the `quotes:approve` permission.
- **[Contract terms & commitment](contract-terms.md)** — contract length, billing interval, the
  minimum-commitment floor, the price ramp, and how the committed value is computed through the
  engine.
- **[Order form & acceptance](order-form-and-acceptance.md)** — the hosted, branded, CSP-safe order
  form; the e-signature-by-acceptance evidence; and the signature-provider seam (DocuSign, etc.).
- **[Quote → subscription provisioning](provisioning.md)** — how an accepted quote provisions a
  subscription, idempotently, and the modeling boundary for commitment/ramp.

## Permissions

| Capability | Slug |
| --- | --- |
| View quotes + the approval queue | `quotes:read` |
| Author, send, expire, clone quotes | `quotes:manage` |
| Approve / reject above-threshold quotes | `quotes:approve` |

`quotes:approve` is deliberately separate from `quotes:manage` so a rep cannot self-approve their
own above-threshold deals.
