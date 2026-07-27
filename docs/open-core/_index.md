---
title: Open core
description: The open-core model — a complete MIT base app, a console-kit plugin socket with deny-by-default capability gating, six commercial plugins, and the cloud overlay that composes them.
weight: 60
---

# Open core

Cbox Billing is **open core**. The base app (`cboxdk/cbox-billing`) is **MIT** and
complete on its own: use it, modify it, self-host it, embed it commercially, or run it
as a hosted service for others — there is no restriction beyond keeping the copyright
notice. The engine and its satellites (`laravel-billing`, `laravel-tax`, `laravel-nexus`,
the client SDK, the gateway adapters) are MIT on the same terms.

Every plugin is MIT too — what the commercial offering sells is the hosted service and
the composed image, not a licence to the code. A private composition
(`cbox-billing-cloud`) overlays six separately-licensed plugins on top of the base
image — and it does so with **zero edits to the app**, purely through Laravel
auto-discovery and a console-kit runtime socket.

## The two halves

| | Open base | Commercial composition |
| --- | --- | --- |
| Repo | `cboxdk/cbox-billing` (public, MIT) | `cbox-billing-cloud` (private, proprietary) |
| Contains | The app + its public vendor tree | No app source, no plugin source — just the overlay + prod config |
| Image | `ghcr.io/cboxdk/cbox-billing` | `ghcr.io/cboxdk/cbox-billing-cloud` (FROM the base) |

## In this section

| Page | What |
| --- | --- |
| [The plugin model](plugin-model.md) | The console-kit socket — how a plugin registers nav, UI, features, and migrations without touching the app. |
| [Capability gating](capability-gating.md) | Deny-by-default: features (presence) vs entitlements (the license-backed `CapabilityGate`). |
| [Commercial plugins](commercial-plugins.md) | What each of the six plugins adds. |
| [Composition](composition.md) | How the cloud overlay `composer require`s the plugins with a build secret. |

## The two gates (never conflated)

The app is careful to separate two different questions:

- **Is the plugin installed?** → a **console-kit feature** (a hard presence gate).
  When off, the page is hidden and its routes 404.
- **Does the plan/license entitle it?** → the **`CapabilityGate`** (deny-by-default,
  license-backed). When the entitlement is absent, the capability stays locked.

"Plugin installed" is never the same as "plan entitles." A plugin can be present but
locked. See [Capability gating](capability-gating.md).

## Related documentation

- [Deployment → Cloud composition](../deployment/cloud-composition.md)
- [Concepts → On-prem licensing](../concepts/licensing.md)
- Console socket: <https://github.com/cboxdk/laravel-console-kit>
