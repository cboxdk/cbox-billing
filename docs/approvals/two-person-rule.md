---
title: The two-person rule
description: How separation of duties is enforced — self-approval refusal, one decision per checker, the M-of-N quorum, reject-as-veto, and the approvals:decide permission.
weight: 30
---

# The two-person rule

The engine enforces separation of duties (*maker-checker*) in the decision service, not merely in
the UI. Three invariants hold:

## 1. A maker cannot approve their own request

The checker's operator subject (`sub`, from Cbox ID) is compared to the maker's. If they match,
the decision is refused (`ApprovalDenied::selfApproval()`) — server-side, so it holds regardless of
what the UI offers. One decision is allowed per checker (a database `UNIQUE(request, approver)`
backs it), so "N approvals" always means **N distinct people**.

## 2. Nothing runs without the quorum

- A **reject** by any single checker vetoes the whole request — it becomes `rejected` and the held
  action never runs.
- A **partial** approval (fewer than `required` distinct approvals) leaves the request `pending`.
- Only the **quorum-reaching** approval runs the held action.

## 3. Deciding is a distinct capability

Approving/rejecting carries the `approvals:decide` permission — separate from the permissions that
let an operator *perform* the underlying action (e.g. `invoices:refund`). So an operator who can
request a refund does not automatically get to approve one. Like the rest of the console, the
permission gate is inert until Cbox ID emits a `permissions` claim and `CBOX_ID_RBAC_ENFORCE` is
on; the self-approval refusal above does **not** depend on that flag.

## Lifecycle

A request moves through: `pending` → (`approved` →) `executed`, or `pending` → `rejected`, and may
also lapse to `expired` (past its configured TTL) or be withdrawn by its maker to `canceled`. Only
a `pending` request accepts a decision; only a quorum-reached one executes; an already-`executed`
request is an idempotent no-op on re-approval (see
[Held actions](held-actions.md)).

## Console

- **Pending queue** (`/approvals`, `approvals:decide`) — every request awaiting a decision, with
  the action, the maker, the org, the amount, the reason, and the human summary + before/after
  effect; approve/reject with a note behind a confirm dialog.
- **My requests** (`/approvals/mine`) — a maker's own submissions and where they stand; the maker
  can cancel a still-pending one.
- A **pending-count badge** on the Approvals nav item, and ⌘K entries for both views.
