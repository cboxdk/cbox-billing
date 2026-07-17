---
title: Capability gating
description: The deny-by-default capability gate every commercial plugin reads — how a consume-license is verified offline to bind a LicenseCapabilityGate, and how it differs from the presence-gating console features.
weight: 62
---

# Capability gating

A composed deployment ships all five commercial plugins in one image. That is only
safe because each plugin is **deny-by-default**: it stays inert until both its
console feature is present **and** the plan/license entitlement unlocks it. This page
is about the second gate — the `CapabilityGate`.

## Two gates, two questions

| Gate | Question | Mechanism | When absent |
| --- | --- | --- | --- |
| **Feature** (presence) | Is the plugin installed / wired? | Console-kit feature registry | Page hidden, routes 404 |
| **Capability** (entitlement) | Does the license entitle this? | `CapabilityGate` (from `cboxdk/license`) | Capability locked |

The app never conflates them. A plugin can be installed (feature present) but locked
(capability denied) — that is exactly the state a free-tier deployment of the cloud
image runs in.

## The single seam every plugin reads

`LicensingServiceProvider` binds one `CapabilityGate` that every commercial plugin
consults. It is resolved **lazily** (a singleton closure that only runs on first use),
so an unconfigured deployment never verifies at boot and simply denies by default.

The binding logic is deny-by-default:

1. Read `CBOX_BILLING_LICENSE_KEY` (the **consume**-license).
2. If it is empty → bind a `DenyingCapabilityGate`. This is the **free tier**: no
   plugin capability unlocks by omission.
3. If it is set → verify the artifact **offline** against the issuer public key
   (`CBOX_LICENSE_PUBLIC_KEY`), honouring the same grace and clock-skew the verifier
   deployment uses, bound to this deployment's `CBOX_BILLING_DEPLOYMENT_ID`. Bind a
   `LicenseCapabilityGate` over the fresh result — so plugins unlock **exactly** the
   license's entitlements.

An unlicensed result (expired-beyond-grace, wrong deployment, bad signature, absent
deployment id) naturally grants nothing. A license minted for one deployment cannot
light up another.

## Consume vs issuer keys

This is the same distinction drawn in [Licensing](../concepts/licensing.md), from the
consume side:

- The **issuer** keys (`CBOX_LICENSE_SIGNING_KEY` / `_PUBLIC_KEY`) are what this app
  uses to sign licenses **for customers**.
- The **consume**-license (`CBOX_BILLING_LICENSE_KEY`) is what **this** deployment
  installs to unlock its **own** bundled plugins.

They are separate keys and separate concerns. Entitlement is delivered via the
license/plan — **not** via an env flag per plugin. There is no "enable plugin X"
toggle; a plugin lights up because the license grants its capability.

## Related documentation

- [The plugin model](plugin-model.md)
- [Concepts → On-prem licensing](../concepts/licensing.md)
- [Deployment → Cloud composition](../deployment/cloud-composition.md)
- License crypto + gate: <https://github.com/cboxdk/license>
