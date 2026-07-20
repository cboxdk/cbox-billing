---
title: Open core
description: The open-core model — a complete ELv2 base app, a console-kit plugin socket with deny-by-default capability gating, five commercial plugins, and the cloud overlay that composes them.
weight: 60
---

# Open core

Cbox Billing is **open core**. The base app (`cboxdk/cbox-billing`, Elastic License
2.0) is source-available and complete on its own: you may use, modify, and self-host
it freely — including commercially — but you may not provide it to third parties as
a hosted or managed service. A private composition (`cbox-billing-cloud`) overlays
five commercial plugins on top of the base image — and it does so with **zero edits
to the app**, purely through Laravel auto-discovery and a console-kit runtime socket.

## The two halves

| | Open base | Commercial composition |
| --- | --- | --- |
| Repo | `cboxdk/cbox-billing` (public, ELv2) | `cbox-billing-cloud` (private, proprietary) |
| Contains | The app + its public vendor tree | No app source, no plugin source — just the overlay + prod config |
| Image | `ghcr.io/cboxdk/cbox-billing` | `ghcr.io/cboxdk/cbox-billing-cloud` (FROM the base) |

## In this section

| Page | What |
| --- | --- |
| [The plugin model](plugin-model.md) | The console-kit socket — how a plugin registers nav, UI, features, and migrations without touching the app. |
| [Capability gating](capability-gating.md) | Deny-by-default: features (presence) vs entitlements (the license-backed `CapabilityGate`). |
| [Commercial plugins](commercial-plugins.md) | What each of the five plugins adds. |
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
