---
title: Held actions & execute-on-approve
description: The held-action command pattern — one execution path for direct and approved runs, exactly-once idempotent execution under a row lock, and the engine-level idempotency backstop.
weight: 40
---

# Held actions & execute-on-approve

## One code path, two moments

A held action is a small command implementing `ApprovableAction`. The invariant that makes the
two-person rule sound is that **`execute()` is the one and only place the mutation happens**, and
it is reached through the exact same call whether the action runs directly (below threshold) or on
approval. There is no second, un-gated code path — an approved refund and a direct refund run
identical code and produce identical audit events.

- **Direct run** (policy does not require approval): the gate calls `validate()` then `execute()`
  immediately.
- **Held run**: the action's `type()` + `payload()` are persisted on the `ApprovalRequest`. On the
  quorum-reaching approval, the executor rebuilds the action from the stored payload via the
  registry factory, calls `validate()` again (the world may have moved since capture), then
  `execute()`.

## Exactly-once execution

Execution reads the request **under a row lock** (`lockForUpdate`) inside a transaction and
no-ops if the request is already `executed`. So a double-submit or a re-approval can never run the
money effect twice. This status guard is the first line of defence; the held action's own
engine-level idempotency is the second:

- A **refund** carries a stable action id (`op-refund:<key>`); the engine refunder is idempotent on
  it and caps the cumulative refund at the amount charged, so even a retried execution never issues
  a second credit note.
- A **wallet adjustment** and other actions rely on the status-guarded exactly-once execution.

Re-approving an already-executed request returns its stored result unchanged — a genuine no-op.

## Validate before you run — twice

`validate()` re-checks the action can still run in the *current* world (the invoice is still
refundable, the target still exists), throwing a domain `*ActionDenied` the console surfaces. It
runs before a direct execute **and** again at approval time, because a request may sit in the queue
while the world changes.

## Expiry sweep

When `expire_after_days` is set, a pending request past its expiry can be lapsed to `expired` by
`ApprovalService::expire()`. An expired request never executes; the expiry is recorded on the audit
trail. Wire the sweep into the scheduler if you use TTLs.

## Audit

The engine records `approval.requested`, `approval.approved`, `approval.rejected`,
`approval.executed`, `approval.canceled`, and `approval.expired` on the tamper-evident trail, in
addition to the underlying action's own event (e.g. `invoice.refunded`, `wallet.adjusted`,
`customer.suspended`, `plan.archived`) which the held action records on execute — so both the money
effect and its governance are auditable.
