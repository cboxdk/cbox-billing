---
title: Consolidated reporting & FX
description: Multi-entity, multi-currency consolidated MRR/ARR with FX normalization — real ECB reference rates (plus operator overrides), the as-of and rounding policy, and the auditable per-currency and per-entity breakdowns.
weight: 57
---

# Consolidated reporting & FX

Cbox Billing reports recurring revenue two ways, side by side:

- **Per-currency** — the original `RevenueMetrics` / `RevenueAnalytics` read models, one line
  per currency, never mixing currencies. Unchanged and authoritative for a single-currency book.
- **Consolidated** — the whole multi-entity, multi-currency book normalized to one **reporting
  currency** with real foreign-exchange rates, so a multi-subsidiary or global seller sees a
  single consolidated MRR/ARR and how each selling entity and each currency rolls into it.

Consolidation is a **reporting overlay only**. The ledger, invoices and the currency lock always
stay in each transaction's own currency; FX conversion never rewrites a stored amount.

The cardinal rule: **rates are never fabricated.** They come from the European Central Bank's
public reference-rate feed or an operator/treasury override. A pair with no resolvable rate is
reported honestly as "rate unavailable" — never converted at an assumed number.

## Pages

- [FX rates](fx-rates.md) — the ECB feed adapter (cited), operator overrides, the `fx_rates`
  store, the as-of / cross-rate / rounding policy, and the `fx:refresh` pull.
- [Consolidated MRR/ARR](consolidated-reporting.md) — the consolidated read models, their
  formulae, the reporting-currency and entity filter, and the auditable breakdowns.
