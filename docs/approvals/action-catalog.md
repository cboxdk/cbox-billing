---
title: Approvable-action catalog
description: The typed catalog of sensitive mutations the approval engine can hold behind a second-person decision, the parameters each captures, and how to register a new approvable action.
weight: 10
---

# Approvable-action catalog

Each approvable action is a typed case of `ApprovalActionType` (a stable dotted
`<resource>.<verb>` slug) with a registered factory that builds the held action. The registry is
**deny-by-default**: an action type with no registered factory cannot be held *or* executed, so a
config entry that names an unknown action fails closed rather than letting a mutation through
unapproved.

## Actions wired through the gate

| Type slug | What it holds | Amount used for the threshold | Underlying service |
|---|---|---|---|
| `invoice.refund` | Reverse an invoice as a credit note (money out) | Refund net (partial) or invoice gross (full) | `RunsInvoiceOperations::refund()` |
| `wallet.adjust` | Grant or debit organization wallet credit | The credit amount | `AdjustsWallet` |
| `customer.suspend` | Suspend a customer organization (access held, billing untouched) | *none* — always holds when enabled | app org model + engine `AccountStanding` |
| `plan.archive` | Archive a plan (close it to new signups) | *none* — always holds when enabled | `PlanAuthoring::archive()` |

An action with **no money dimension** (`customer.suspend`, `plan.archive`) ignores the numeric
threshold: enabling it means *every* invocation is held for a second person.

Credit-note issuance is not a separate approvable action — a credit note is only ever created as
the side effect of a refund reversal, so it is governed by `invoice.refund`.

## What a held action captures

Every held action serializes a JSON-safe **payload** — only the ids/scalars needed to reconstruct
and run it later, never resolved models or secrets — plus a **context** (the org, the amount, the
currency, the target resource) captured on the `ApprovalRequest` row so the queue renders without
rebuilding the action. For example a held refund captures `{invoice_id, net_minor, reason,
idempotency_key}`; a held wallet adjustment captures `{organization_id, direction, pool,
denomination, amount, reason, actor, expires_in_days}` — where `actor` is the **maker**, so the
wallet row records who *originated* the adjustment even though a different operator approved it.

## Registering a new approvable action

1. Add a case to `App\Billing\Approvals\Enums\ApprovalActionType`.
2. Implement `App\Billing\Approvals\Contracts\ApprovableAction` — `type()`, `context()`,
   `payload()`, `validate()`, `describe()`, `execute()`. `execute()` must call the *same* domain
   service the direct path uses and record its own audit event, so an approved run is identical to
   a direct one.
3. Implement `App\Billing\Approvals\Contracts\BuildsApprovableAction` — a factory that rebuilds
   the action from its payload (re-loading target ids from the database).
4. Register the factory in `App\Providers\ApprovalServiceProvider`.
5. Add a `config('billing.approvals.actions.<slug>')` entry (see
   [Policy & thresholds](policy-and-thresholds.md)).
6. Route the controller through the gate: build the action via the registry and call
   `ApprovalGate::run()`.
