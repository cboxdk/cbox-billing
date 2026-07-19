---
title: Chain integrity & audit:verify
description: How audit:verify walks the hash chain and detects a break, the console chain-status indicator, and the honest scope of the guarantee — tamper-evident, not tamper-proof.
weight: 20
---

# Chain integrity & `audit:verify`

## The command

```
php artisan audit:verify
```

`audit:verify` walks the trail in `sequence` order and checks three invariants per row:

1. `sequence` increments by exactly 1 (no gap, no reorder);
2. `prev_hash` equals the previous row's stored `hash` (the link is intact);
3. the stored `hash` equals the recomputed `hash` (the row's contents are unmodified).

The first row to violate any invariant is reported as the break point. Exit code is **0** for an
intact chain and **1** for a break, so CI or a monitor can gate on it. The walk streams with a
chunked cursor, so verification is memory-bounded regardless of trail size.

Example break output:

```
Chain BROKEN at sequence 42 after 41 good event(s): stored hash does not match recomputed hash (row modified)
```

## The console indicator

The **Audit log** area shows a live chain-status banner (verified / broken) computed from the
same verifier, so an operator sees integrity at a glance without dropping to the CLI.

## Honest scope — tamper-evident, not tamper-proof

The chain is **unkeyed** (a plain SHA-256, not an HMAC or a signature). That is a deliberate,
stated limitation:

- It **reliably detects**: an in-place edit of a row, a re-linked `prev_hash`, a deleted or
  reordered row, a truncated tail, and accidental corruption. Combined with the DB-level
  append-only guard, casual or partial tampering is caught.
- It **cannot prove** the trail was never rewritten wholesale. An actor who holds both direct
  database access *and* the application (enough to disable the trigger and re-run the hashing)
  can recompute a fresh, internally-consistent chain.

To harden further, an operator can periodically **export** the trail (see the DSAR/export docs)
to append-only external storage or a WORM bucket, or ship each `hash` to a notary/transparency
log. The chain gives you the local evidence; off-box anchoring gives you the external proof. We
document this rather than over-claiming a "tamper-proof" ledger the mechanism does not deliver.
