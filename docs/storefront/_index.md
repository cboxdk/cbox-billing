---
title: Storefront — pricing tables & paywall
description: The embeddable, seller-branded, self-contained pricing table a marketing site drops in, the paywall an app redirects blocked users to, the embed snippet (iframe vs script), and the checkout deep-link contract — honest about what is hosted vs embeddable.
weight: 66
---

# Storefront — pricing tables & paywall

The storefront is the public, customer-facing edge of the catalog: an **embeddable pricing
table** a marketing site drops in, and a **paywall** an app redirects a blocked user to. Both
are projected from the same catalog the engine prices and provisions from — products, plans,
per-currency prices, metered entitlements, and boolean/config features — so what a visitor sees
is always the catalog truth, never a hand-maintained copy.

Every storefront surface is **self-contained and CSP-safe**: inline CSS and JS, no external
stylesheet, font, script, or host (the same discipline as the `/api/docs` reference page). A
pricing table is addressed by its public `key` at `/pricing/{key}`; the paywall by the org plus
the gated capability at `/paywall`. Neither is behind the provider auth gate.

## The three surfaces

- **`GET /pricing/{key}`** — the standalone, marketing-grade pricing page: branded plan columns
  with a featured column, currency + monthly/yearly toggles, and a feature-comparison matrix.
- **`GET /pricing/{key}/embed`** — the same table trimmed for iframe embedding (transparent, no
  page chrome, reports its height to the host frame).
- **`GET /pricing/{key}/embed.js`** — a tiny self-contained loader that injects and auto-sizes
  the iframe where it is dropped.
- **`GET /paywall?org=…&feature=…`** (or `&meter=…`) — the hosted "upgrade to unlock" panel.

## Sections

- **[Authoring a pricing table](pricing-tables.md)** — the console CRUD, the plan columns and
  feature-comparison matrix, currencies, the interval toggle, and the live preview.
- **[Embedding](embedding.md)** — the iframe vs script embed snippet, auto-sizing, and the
  CSP-safety guarantee.
- **[The paywall](paywall.md)** — the hosted page and the drop-in partial, both reusing the
  UpgradeGate.
- **[Branding](branding.md)** — how a table and the paywall wrap around a selling entity's brand.
- **[The checkout deep-link contract](checkout-deep-link.md)** — how a CTA hands off into
  checkout, and why a public table cannot mint a session itself (hosted vs embeddable).
