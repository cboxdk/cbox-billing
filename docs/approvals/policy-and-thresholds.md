---
title: Policy & thresholds
description: The billing.approvals config — enabling an action, its amount threshold, how many approvals are required, the deciding permission, and pending-request expiry.
weight: 20
---

# Policy & thresholds

The whole engine is driven by `config('billing.approvals')`. **Every action ships disabled**, so
an unconfigured deployment behaves exactly as before — no held requests, no regression. An
operator opts each sensitive action in.

```php
'approvals' => [
    'permission' => env('CBOX_BILLING_APPROVALS_PERMISSION', 'approvals:decide'),
    'expire_after_days' => $intOrNull(env('CBOX_BILLING_APPROVALS_EXPIRE_DAYS')),
    'actions' => [
        'invoice.refund'   => ['enabled' => false, 'threshold_minor' => null, 'required' => 1],
        'wallet.adjust'    => ['enabled' => false, 'threshold_minor' => null, 'required' => 1],
        'customer.suspend' => ['enabled' => false, 'threshold_minor' => null, 'required' => 1],
        'plan.archive'     => ['enabled' => false, 'threshold_minor' => null, 'required' => 1],
    ],
],
```

## Per-action keys

- **`enabled`** — *(default `false`)*. When false, the action executes directly, exactly as
  before. Turn it on to route the action through the gate.
- **`threshold_minor`** — when enabled, an amount **at or above** this floor (minor units) requires
  approval; an amount below runs directly. `null` means *no amount gate* → **every** invocation of
  an enabled action requires approval. Actions with no money dimension
  (`customer.suspend`, `plan.archive`) ignore the floor and always hold when enabled.
- **`required`** — how many **distinct** operators must approve before the action runs (the *M* in
  an M-of-N maker-checker). Default `1`. The maker is never counted.

## Global keys

- **`permission`** — the `feature:action` slug a checker needs to decide (approve/reject). Enforced
  by the same flag-held `billing.permission` middleware as the rest of the console — inert until
  Cbox ID emits a `permissions` claim and `CBOX_ID_RBAC_ENFORCE` is on. The two-person rule itself
  is always enforced in the service regardless.
- **`expire_after_days`** — a pending request auto-expires this many days after creation (`null` =
  never). An expired request never executes; sweep them with the scheduled sweep (see
  [Held actions](held-actions.md)).

## Example: refunds over kr 5.000 need one approval

```dotenv
CBOX_BILLING_APPROVE_REFUND=true
CBOX_BILLING_APPROVE_REFUND_THRESHOLD_MINOR=500000
```

A refund below kr 5.000,00 issues its credit note immediately; at or above it, the refund is held
for a second operator and issues **no** credit note until approved.

> The action-type keys (`invoice.refund`, …) are **literal dotted keys** in the `actions` array —
> not a nested `invoice → refund` path. Set them as whole keys when overriding config in a test:
> `config()->set('billing.approvals.actions', ['invoice.refund' => [...]])`.
