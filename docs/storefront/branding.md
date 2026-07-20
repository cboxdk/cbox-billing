---
title: Branding
description: How a pricing table and the paywall wrap around a selling entity's brand — the accent colour, logo, legal footer and support links — resolved through the same BrandingResolver the transactional emails use, with app-level defaults.
weight: 40
---

# Branding

A pricing table and the paywall wrap around a **selling entity's brand**, resolved through the
same `BrandingResolver` the transactional emails use — so the storefront, the checkout, and the
emails a customer receives all carry one consistent identity.

A table names its selling entity in the console. The resolved branding supplies:

- **Accent colour** (`brand_color`) — drives the featured column border, the CTA buttons, and the
  toggles. Injected as a CSS variable on the page root.
- **Logo** (`logo_url`) — shown in the header; when unset, the product name renders as a wordmark.
- **Legal name + registration number** — the footer line of record.
- **Support URL / email** — the footer links (and the paywall's "maybe later" fallback).

Any field a selling entity leaves unset falls back to the app-level defaults
(`config('billing.mail.branding')`) — a table with no entity named uses the default seller.

## A note on the logo and strict CSP

The page itself loads no external asset. The **one** reference that can point off-host is a
configured `logo_url` (an operator-set image). For a strict Content-Security-Policy, either serve
the logo from the same origin as the table, use a `data:` URI, or leave it unset (the wordmark is
fully self-contained). The default branding ships no logo, so the default page is self-contained
end to end.
