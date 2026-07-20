---
title: The paywall
description: The drop-in paywall — a hosted page an app redirects blocked users to, and an embeddable partial an integrator drops in — both reusing the UpgradeGate's required plan and hosted-checkout deep-link rather than recomputing the upgrade logic.
weight: 30
---

# The paywall

When a feature or metered-limit gate blocks a user, the paywall turns the refusal into a
branded "upgrade to unlock" offer. It **reuses the `UpgradeGate`** — the required plan and the
hosted-checkout deep-link are the gate's own output — and only enriches it for display (the human
label of the gated capability and the required plan's price in the org's billing currency). The
upgrade logic is never recomputed here.

## Hosted page

Redirect a blocked user to:

```
GET /paywall?org={org}&feature={feature}
GET /paywall?org={org}&meter={meter}
GET /paywall?org={org}&feature={feature}&return_url={url}
```

The page is self-contained and seller-branded. It shows the gated capability, the required plan
and its price, an **Upgrade** CTA that deep-links straight into the hosted checkout for that plan
(the gate mints/reuses an org-scoped session), and a **Maybe later** link back to `return_url`
(or the seller's support URL). When the org already has the capability, or no reachable plan
grants it, the page states that honestly rather than inventing an offer.

Because the paywall already knows the org, its CTA links **directly** to the hosted checkout —
unlike a public pricing table, which is pre-customer and can only hand off (see
[the checkout deep-link contract](checkout-deep-link.md)).

## Embeddable partial

An integrator who already holds the gate's output (e.g. from a `/reserve` or
`/entitlements/{org}/features` response) can render the panel inline instead of redirecting,
using the shared design-system partial:

```blade
@include('partials.paywall', [
    'upgrade' => $offer,             // the UpgradeGate output: {required_plan, checkout_url}
    'feature' => 'Single sign-on',   // label of the gated capability
    'kind'    => 'feature',          // 'feature' | 'usage'
    'price'   => 'DKK 1.240,00',     // optional formatted price
    'per'     => '/mo',              // optional interval suffix
    'returnUrl' => '/back',          // optional "maybe later" target
])
```

Given no upgrade (a null/empty offer), the partial renders nothing — deny-by-default. For a
compact inline nudge rather than a full panel, `partials.upgrade-gate` is the lighter sibling.
