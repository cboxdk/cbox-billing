---
title: Embedding the table
description: The two embed snippets — a raw iframe (CSP-safest, no script) and a self-contained loader script that injects and auto-sizes the iframe — the height auto-sizing protocol, and the self-contained/CSP-safety guarantee.
weight: 20
---

# Embedding the table

A pricing table is dropped into a marketing site with one of two snippets, both shown on the
table's console detail page. Every served page is **self-contained** — inline CSS and JS, no
external stylesheet, font, script, or host — so it is safe under a strict Content-Security-Policy.

## Option 1 — iframe (recommended, no script)

```html
<iframe src="https://your-host/pricing/plans/embed" title="Pricing"
        loading="lazy" style="width:100%;border:0;min-height:640px"></iframe>
```

This is the **CSP-safest** option: it runs no script on the host page, so the host's CSP only
needs `frame-src https://your-host`. The one caveat is height — a plain iframe cannot grow to its
content on its own, so either set a generous `min-height` (as above) or use the script snippet,
which auto-sizes it.

## Option 2 — loader script (auto-sizing)

```html
<script async src="https://your-host/pricing/plans/embed.js"></script>
```

The loader is a small self-contained script that injects the `/embed` iframe where the tag sits
and resizes it to the table's reported height, so there is never an inner scrollbar. Because it
executes a script from your host, the host page's CSP must allow that script's origin
(`script-src https://your-host`).

### Auto-sizing protocol

The embed page posts its height to the parent frame whenever it loads or resizes:

```js
window.parent.postMessage({ type: 'cbox-pricing-height', key: '<table key>', height: <px> }, '*');
```

The loader listens for messages matching its own table `key` and sets the iframe height. If you
embed the iframe directly (Option 1) and want the same behaviour, listen for that message
yourself.

## Currency & interval toggles

All price permutations (every currency × interval) are precomputed server-side and embedded in
the page as an inert JSON blob; the toggles switch the displayed price and CTA entirely
client-side, with **no** network call — which is what keeps the page self-contained.

## Cross-origin host

By default the snippets point back at `APP_URL`. If the table must load from a different public
hostname than the app runs on, set `CBOX_BILLING_STOREFRONT_EMBED_BASE_URL` and the snippets use
that origin instead.
