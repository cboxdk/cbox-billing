---
title: Approvals — the two-person rule
description: A general maker-checker approval engine for sensitive operator actions. A refund, wallet adjustment, suspension, or plan archival above a configured threshold is HELD as a pending request and does not take effect until a SECOND operator approves it, at which point the captured action runs exactly once.
weight: 68
---

# Approvals — the two-person rule

The **Approvals** area is enterprise governance for money-sensitive operator actions. Some
console mutations move money or change a customer's standing — a refund, a wallet credit
grant/debit, a customer suspension, a plan archival. The approval engine lets an operator
require that such an action, above a configurable threshold, is **approved by a second person**
before it takes effect. This is the classic **maker-checker** control (a *two-person rule*):
the operator who requests the action (the *maker*) can never be the one who approves it (the
*checker*).

It generalizes the bespoke CPQ deal-desk quote approval into one reusable engine that any
sensitive mutation routes through, so thresholds, the two-person rule, and the audit trail are
implemented once rather than per action.

## How it works

1. A controller assembles the mutation as a **held action** (a small command object) and hands
   it to the **approval gate**.
2. The gate asks the **policy** (from `config('billing.approvals')`) whether this action, at its
   amount, requires approval.
   - **No** (disabled, or below the threshold): the action runs immediately — exactly the
     behaviour before the engine existed, so there is no regression.
   - **Yes**: the action is captured as a pending `ApprovalRequest` (its typed action + the
     serialized parameters needed to run it later) and **does not take effect**. The originating
     screen shows "submitted for approval".
3. A **different** operator opens the **pending queue**, reviews the human summary + before/after
   effect, and **approves** or **rejects**. On approval (once the required number of distinct
   approvals is met) the held action runs **exactly once**, through the *same* code path a direct
   action uses. On reject nothing runs.

Every transition — requested, approved, rejected, executed, expired, canceled — is written to the
tamper-evident [audit trail](../audit-compliance/_index.md).

## In this section

- **[Approvable-action catalog](action-catalog.md)** — the typed actions the engine can hold, and how to add one.
- **[Policy & thresholds](policy-and-thresholds.md)** — the `billing.approvals` config: enable, thresholds, quorum, permission, expiry.
- **[The two-person rule](two-person-rule.md)** — self-approval refusal, the M-of-N quorum, and the permission gate.
- **[Held actions & execute-on-approve](held-actions.md)** — the command pattern, idempotent execution, and how a held run is identical to a direct one.

## What it does NOT do

- It does not change behaviour for any action left disabled (the default) — those execute directly.
- It is **tamper-evident, not tamper-proof**: like the audit trail, it records and links every
  decision, but a sufficiently privileged database operator could still alter rows out of band.
- Permission enforcement (`approvals:decide`) lights up only when Cbox ID emits a `permissions`
  claim **and** `CBOX_ID_RBAC_ENFORCE` is on — the same honest RBAC rollout as the rest of the
  console. The two-person rule itself is always enforced in the service, regardless of that flag.
