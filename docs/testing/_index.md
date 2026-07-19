---
title: Testing & sandbox
description: The sandbox / test-mode plane — an isolated dataset an integrator can safely experiment against, a fake gateway with no real charges or emails, and a fast-forwardable test clock for simulating renewals, trials and dunning.
weight: 55
---

# Testing & sandbox

Cbox Billing ships a **sandbox** so an integrator can build and verify an integration
against real billing behaviour without touching live data, charging a real card, or
sending a real email — and without waiting for real time to pass.

It has three parts:

- **[Test mode](test-mode.md)** — a `livemode` plane partition. A test API token (or the
  console's test-mode toggle) operates only on an isolated `livemode=false` dataset; a live
  credential can never see or touch it, and vice-versa. Test-mode payments go through a fake
  gateway and test-mode notifications are captured, never delivered.
- **[Test clocks](test-clocks.md)** — a named, fast-forwardable virtual clock. Bind test
  subscriptions to a clock and advance its time to run a year of renewals, a trial
  conversion, or a full dunning schedule in seconds — deterministically and idempotently.

The sandbox is **additive and off by default**: every existing row is `livemode=true`, every
credential is live unless explicitly minted or toggled otherwise, and the live scheduler only
ever sees live data.
