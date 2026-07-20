---
title: Contract terms & commitment
description: The contract length, billing interval, start date, the per-period minimum-commitment floor, and the price ramp — and how the first invoice and the committed contract value are computed through the engine's MinimumCommitment and RampSchedule value objects.
weight: 30
---

# Contract terms & commitment

A quote carries the **committed deal** on its header, projected into the engine value objects the
totals compute through:

| Term | Column | Engine value object |
| --- | --- | --- |
| Contract length | `term_count` + `term_unit` (day/month/year) | `Cbox\Billing\Catalog\ValueObjects\Term` |
| Billing interval | `billing_interval` (monthly/yearly) | `Cbox\Billing\Subscription\Enums\BillingInterval` |
| Start date | `start_date` (nullable → on acceptance) | — |
| Minimum commitment | `minimum_commitment_minor` (per period) | `Cbox\Billing\Subscription\ValueObjects\MinimumCommitment` |
| Price ramp | `ramp` (JSON step list) | `Cbox\Billing\Subscription\ValueObjects\RampSchedule` |

## How the numbers compute

`App\Billing\Cpq\QuoteCalculator` prices the quote entirely in integer minor units, through the
engine:

1. **Line net** — a plan line prices through the engine tier calculator
   (`Plan::amountFor(currency, quantity)`); a custom line is its unit amount × quantity.
2. **Discounts** — a per-line discount (percent or fixed) reduces the line net; an order-level
   coupon applies to the recurring net through the engine `CouponApplier` and is distributed across
   the recurring lines (remainder-safe).
3. **First invoice** — every line runs through the engine `QuoteBuilder` for the quote's tax
   context, yielding the tax-aware per-line net/tax/gross and the first-invoice totals. If the
   buyer's jurisdiction cannot be resolved (a prospect with no address), the quote is returned
   **tax-pending** — net prices with an honest note, never a fabricated rate.
4. **Committed value** — the minimum **net** the customer is obligated to over the term. For each of
   the term's billing periods the effective recurring is the ramp's amount for that period (or the
   flat recurring net), **floored** by the minimum commitment via `MinimumCommitment::trueUp()`:

   ```
   committedNet = Σ over periods  max( rampAmountForPeriod(i) , minimumCommitment )
   ```

   The committed value is a pre-tax projection because tax varies by period and place; the first
   invoice carries the real tax.

### The number of periods

The term length in months divided by the billing interval in months (at least one): a 12-month term
billed monthly is 12 periods; the same term billed yearly is 1 period.

## The minimum-commitment floor

`minimum_commitment_minor` is the **per-billing-period** spend floor — the same shape as the
engine's `MinimumCommitment`. At period close the engine's true-up bills any shortfall so the floor
is met; the quote's committed value reflects that floor across every period.

## The price ramp

The ramp is an ordered list of `{from_period_index, amount_minor}` steps (index 0 required). The
step covering a period is the greatest `from_period_index ≤ period`, so the price holds until the
next step. The committed-value projection uses the ramp as the authored per-period recurring; the
first invoice always reflects the authored line items.
