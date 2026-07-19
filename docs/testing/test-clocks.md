---
title: Test clocks
description: The fast-forwardable virtual clock — how the BillingClock seam works, its coverage boundary (what reads it vs what still uses the system clock), and the advance mechanics that run due renewals, trials and dunning deterministically and idempotently.
weight: 20
---

# Test clocks

A **test clock** is a named virtual clock you bind test subscriptions to. Advancing its time
runs the due billing logic for those subscriptions — renewals, trial conversions, dunning —
exactly as it would have fired over real elapsed time, but in seconds. This is how you test a
year of renewals, a trial conversion, or a full dunning schedule without waiting.

## The clock seam

The billing time-sensitive services read "now" through a `BillingClock` seam instead of
calling `CarbonImmutable::now()` directly. In live mode it returns the real clock. While a test
clock is being advanced, it returns that clock's virtual time, so every date-sensitive decision
(is this period due? has this trial ended? is this dunning attempt due?) is made against the
virtual clock.

### Coverage boundary (read this)

The seam is deliberately **bounded**. It is read by exactly the billing DECISION paths a test
clock drives:

- **renewal** boundaries and period advance (`CycleRenewalService`)
- **trial conversion** (`TrialService`)
- **dunning cadence** — the backoff schedule and each attempt's due check
  (`PaymentRetryService`)
- **subscription anchoring** — a new subscription's period and trial-end windows
  (`SubscriptionService`)
- **retirement cutoffs** on the renewal path and in `PlanRetirementService`
- **coupon duration** — `once` / `repeating:N` / `forever` is consumed per issued renewal
  invoice, so it is driven by the clock through the renewal path (cycle-count, not a wall-clock
  read; the only wall-clock read in the coupon path is the coupon code's own `redeem_by`
  expiry).

Everything else still uses the system clock, on purpose. Incidental `now()` reads — audit
stamps, `last_used_at`, `archived_at`, ledger event timestamps, reporting windows, idempotency
TTLs, hosted-session/license expiry timestamps — are **not** time-sensitive to a virtual clock
and are intentionally left out of the seam. The scheduled live billing commands and jobs also
read the system clock (they only ever process live data). A fully-correct "everything reads the
seam" would be a repo-wide `now()` refactor beyond the billing decision paths; the billing
paths are done properly and the boundary is drawn here honestly rather than half-done.

## Advance mechanics

Advancing a clock is **event-stepping**: rather than jump straight to the target and stamp
everything at the target instant, the advancer walks the clock to each due instant in order —
the next renewal boundary, the next trial end, the next dunning attempt — sets the virtual
clock to exactly that instant, runs everything due at it, then finds the next. So a monthly
subscription advanced a year fires twelve renewals on the right twelve dates, each invoice
period-correct, **via the same engine path** the scheduled passes use.

The whole advance runs in the **test plane at the step's virtual time**, so it only touches
`livemode=false` rows, charges route through the fake gateway, and mail is captured, not sent.

It is **deterministic and idempotent**:

- each service is idempotent on its own boundary (a renewal whose period already rolled grants
  nothing new; a settled retry is not re-charged; a per-(invoice, attempt) claim guards
  dunning);
- re-advancing to a time already reached is a no-op — advancing to the same target twice never
  double-invoices.

A safety cap bounds the number of virtual steps so a pathological input cannot loop forever.

## Charge outcome (driving dunning)

Each clock carries a `charge_outcome`: `succeed` (the default — renewals settle clean) or
`decline` (the fake gateway fails the bound subscriptions' charges, opening the smart-retry
schedule). Flip it to `decline` and advance past the backoff offsets to watch dunning progress
attempt-by-attempt to its terminal action.

## Advancing from the console

**Settings → Test clocks** → create a clock, bind test subscriptions, set its charge outcome,
and advance it to a target date. The page shows the resulting subscriptions' state and the
invoices raised.

## Advancing from the API

`POST /api/v1/test/clocks/{id}/advance` with a **test-mode** token and a `target` datetime:

```http
POST /api/v1/test/clocks/42/advance
Authorization: Bearer cbt_…
Content-Type: application/json

{ "target": "2027-01-01T00:00:00Z" }
```

The response reports what fired:

```json
{
  "clock": { "id": 42, "name": "Renewals scenario", "now_at": "2027-01-01T00:00:00+00:00" },
  "advanced_from": "2026-01-01T00:00:00+00:00",
  "renewals": 12,
  "trial_conversions": 0,
  "dunning_attempts": 0,
  "invoices": 12
}
```

A live token is refused (403) — a test clock can only ever be driven from the sandbox.
