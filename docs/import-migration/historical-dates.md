---
title: Historical-date preservation
description: How the importer preserves signup dates, subscription period anchors, MRR-movement timing and invoice dates so MRR history and cohorts stay correct after migration — and why historical invoices are imported as faithful records rather than re-issued.
weight: 40
---

# Historical-date preservation

A migration that stamps every record with "now" destroys the seller's history — cohorts collapse
into one signup month, MRR movements all land today. The importer preserves the source timestamps
so reporting is faithful across the cut-over.

## What is preserved

- **Organizations** — the customer's original signup time becomes the org's `created_at` (its
  cohort).
- **Subscriptions** — the `current_period_start` / `current_period_end` anchors, `trial_ends_at`,
  `canceled_at`, and the original `created_at` are all set from the source.
- **MRR movements** — the subscribe path records an MRR movement; the importer re-times it to the
  subscription's real signup, and:
  - a **serving/paying** sub (active, past_due) keeps its new-logo movement dated at signup;
  - a **trial / paused** sub contributes nothing yet (the auto-recorded movement is removed);
  - a **canceled** sub shows the new-logo movement at signup **and** a churn movement at its
    cancellation date — so it nets to zero MRR now but is correct over time.
- **Invoices** — `issued_at`, `period_start` / `period_end`, and (for a paid invoice) `paid_at`
  are preserved.

`updated_at` on every record is the import time (so you can see what a migration touched);
`created_at` and the domain dates are the historical ones.

## Historical invoices are faithful records, not re-issued

Going-forward invoices are issued through the engine's invoicer (legal numbering + tax
recomputation). A **historical** invoice must not be — re-issuing it would assign a **fresh legal
number** and **recompute tax**, destroying the closed record. So the importer writes historical
invoices as **faithful records**: the original number, currency, subtotal/tax/total (minor units),
status and dates are preserved verbatim, under a namespaced pseudo-seller (`imported:<source>`) so
their numbers never collide with the app's own gapless sequences.

This is the one deliberate exception to "import through the domain services" — and it is the
correct one: a migrated back-catalogue invoice is a closed record being carried over, not a new
document being issued.

## Simplifications (flagged)

- A canceled subscription is still opened (then marked canceled) so it is a faithful record; its
  wallet grants are provisioned and not retroactively forfeited. Enforcement never serves a
  canceled subscription, so this is cosmetic, but it is a known simplification.
- MRR movements are reconstructed as new-logo (+ churn for canceled) at the source dates; mid-life
  expansions/contractions from the provider's history are not replayed — only the current plan is
  imported.
