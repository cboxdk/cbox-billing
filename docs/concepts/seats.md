---
title: Seats
description: The purchased + explicitly-assigned seat model — purchased Full seats drive billing, assignment hands them to eligible members, Light members are free, and an optional auto-assign mode fills seats on join.
weight: 43
---

# Seats

Cbox Billing separates **what you pay for** from **who is using it**. A seat is bought
explicitly and then assigned to a specific member — membership changes never move the bill.

## Purchased vs assigned

| Concept | What it is | Where it lives | Bills? |
| --- | --- | --- | --- |
| **Purchased Full seats** | The subscription's seat **quantity** — what the plan's per-seat price charges. | `subscriptions.seats` | **Yes** — the only billing driver. |
| **Assignment** | One purchased Full seat handed to a specific eligible member. | `seat_assignments` (org, subject, source, assigned_at) | No — it moves no money; it spends a purchased seat. |
| **Eligibility** | Which members *may* hold a seat. | `cbox_id_access_grants` (the access mirror the Cbox ID webhooks maintain) | No. |

The load-bearing rule: **purchased Full seats are the sole billing driver.** An admin buys
or releases them explicitly, which calls the engine's own `changeQuantity()` — a prorated
charge (or credit), a re-established per-seat allotment, and an MRR movement. **No membership
event ever changes the billed quantity.**

## Full vs Light

- **Full** — a member with a `seat_assignment`. They hold one purchased Full seat and are
  billed as part of the subscription quantity.
- **Light** — an eligible member (in the access mirror) **without** an assignment. Counted
  and displayed everywhere seat totals appear, but **free**.

### The invariant

> **assigned count ≤ purchased seats**

Enforced on every write:

- **Assigning with no free seat is refused** — buy more seats first.
- **Releasing purchased seats below the assigned count is refused** — unassign a member
  first. Purchased seats also never drop below one.

Removing a member (the `organization.member_removed` webhook) **releases** their assignment
so the seat can be reused, but does **not** reduce the purchased count — the organization
keeps the seat it paid for until an admin explicitly releases it.

## The auto-assign toggle

By default assignment is **manual**: a joining member only becomes eligible (Light), and an
operator assigns a seat deliberately. Turning `config('billing.seats.auto_assign')` on (env
`CBOX_BILLING_SEAT_AUTO_ASSIGN`) makes a join self-service:

- On `member_added` / `role.assigned`, if the member's role is in `auto_assign_roles`
  (default `billing-admin`, `billing-operator`) **and a purchased seat is free**, the member
  is auto-assigned a seat (`source = auto`).
- It **never auto-buys** and **never exceeds the purchased cap** — with no free seat the
  member simply stays Light.
- If a member's role later drops out of `auto_assign_roles`, an **auto**-sourced seat is
  released; a **manual** seat is never auto-released.

## Configuration

`config/billing.php → seats`:

```php
'seats' => [
    'types' => [
        'full'  => ['label' => 'Full',  'billable' => true],   // the plan's per-seat price
        'light' => ['label' => 'Light', 'billable' => false],  // free today
    ],
    'auto_assign' => (bool) env('CBOX_BILLING_SEAT_AUTO_ASSIGN', false),
    'auto_assign_roles' => ['billing-admin', 'billing-operator'],
],
```

**Light is free today.** The `types` map is deliberately kept price-shaped so a **priced
Light tier** can be introduced later (add a price to the `light` type) without a breaking
change to the model, the reporting, or the API — Light stays free until then.

## Surfaces

- **Console** — the subscription-detail **Seats** panel: the purchased count with **Buy /
  Release** controls (guardrailed against the assigned count), the assigned **Full** members
  with **Assign / Unassign**, the **Light** members list, and Full vs Light totals.
- **Management API** — `GET /subscriptions/{org}/seats` reads the breakdown;
  `POST /subscriptions/{org}/seats` sets the purchased count (buy/release);
  `POST /subscriptions/{org}/seats/assign` and `.../seats/unassign` move a member between
  Full and Light. A refused invariant is a `409`.
- **Reporting** — MRR reflects the **purchased** Full-seat count (you bill what is
  purchased); Full-billed vs Light-free counts are surfaced wherever seats appear.

## Related

- [Subscriptions & lifecycle](subscriptions-and-lifecycle.md) — the quantity change the seat
  purchase rides on.
- [Identity: tenancy & the access mirror](../identity/tenancy.md) — how eligibility is kept
  fresh by the Cbox ID provisioning webhooks.
