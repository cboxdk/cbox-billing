---
title: Catalog CRUD
description: The routable pages, full CRUD, and archive-vs-hard-delete decision for every catalog resource — products, plans, plan prices, entitlements, credit grants, and meters — plus the referential guards that keep grandfathering and the currency lock intact.
weight: 20
---

# Catalog CRUD

Every catalog resource has routable list/detail pages and full create/edit/delete authoring,
gated by `billing.permission:catalog:manage` (writes) and `catalog:read` (reads). Reads go
through a read model (`ProductReport`, `PlanReport`, `MeterReport`, plus the existing
`CatalogReport`); writes go through an authoring service (`ProductAuthoring`, `PlanAuthoring`,
`PlanPriceAuthoring`, `PlanEntitlementAuthoring`, `PlanCreditGrantAuthoring`, `MeterAuthoring`)
so the controllers stay thin. Every destructive control carries the Wave 1 confirm dialog
**and** a server-side guard — the dialog is UX only, never the enforcement.

## Archive vs hard-delete, per resource

The rule: **archive (soft-deactivate) where referential integrity or history matters;
hard-delete only a never-referenced draft.** A guard that refuses a hard-delete raises
`CatalogActionDenied`, which the controller catches and flashes back — the referenced row
survives.

| Resource | Hard-delete allowed when… | Otherwise | Guard |
| --- | --- | --- | --- |
| **Product** | it groups **zero plans** | archive (`archived_at`) | refuse delete while any plan references it |
| **Plan** | **no subscription** (serving or historical) references it | archive (`active = false` → engine `Legacy`) | refuse delete while any subscriber is on it; cascades prices/tiers/entitlements/grants when safe |
| **Plan price** | **no serving subscriber** bills in that currency on a live-or-legacy plan | (no soft state — the engine resolves exactly one version per currency) | refuse removal that would strip a grandfathered currency (the currency lock) |
| **Entitlement** | always (revert to deny-by-default) | — | none needed; removing a bucket denies that meter for the plan |
| **Credit grant** | always (idempotent, time-keyed) | — | none needed; nothing further vests, already-vested credits stand |
| **Meter** | **no entitlement references it and no recorded usage** | archive (`archived_at`) | refuse delete while referenced or with usage history |

Products and meters gain a nullable `archived_at`; plans reuse the existing `active` flag
(archived = legacy). Archived products/meters are hidden from the "new plan" / "new
entitlement" pickers but still resolve for everything already pointing at them.

## Grandfathering and the currency lock stay intact

- **Plan edits are metadata only** — name, interval, product, active. A plan's money lives in
  the versioned per-currency `PlanPrice` authoring, which the engine resolves per subscriber,
  so editing a plan through this surface can never reprice an existing subscriber.
- **Interval is `month` or `year` only** — the two cadences the billing engine can represent
  and renew (`BillingInterval` carries just Monthly and Yearly). `week` and `quarter` are
  refused by the authoring guard (server-side, not just the form): they were previously billed
  on a monthly cadence, so a quarter over-charged 3× and a week under-charged. A genuine
  sub-monthly or quarterly cadence needs an engine feature, not an app workaround; any legacy
  plan stored on `week`/`quarter` is normalized to `month` by migration.
- **Archiving a plan** flips it to `Legacy` (a valid transition source, closed to new
  signups); current subscribers keep their plan and their grandfathered price untouched.
- **Removing a price version** is refused while a serving subscriber's org is billed in that
  currency, so the one-way currency lock the engine finalized on first invoice is never
  broken out from under them. An unused currency's price removes cleanly.
- **Meter aggregation is engine-facing**: the console-authored `aggregation` flows through
  `PlanEntitlement::toMeterPolicy()` into the resolved `MeterPolicy`, so the billable-quantity
  the engine computes matches what the operator chose (default `sum`, unchanged for existing
  meters).
