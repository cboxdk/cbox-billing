---
title: Cloud composition
description: The private cbox-billing-cloud overlay — how it builds FROM the open image with the composer_auth secret, what it composes, and the plugin pinning story.
weight: 72
---

# Cloud composition

The commercial SaaS image, `ghcr.io/cboxdk/cbox-billing-cloud`, is the open base
image **overlaid** with the private plugins and the SaaS-only configuration. This
page is the deployment view; the mechanics are in
[Open core → Composition](../open-core/composition.md).

## What it is

The `cbox-billing-cloud` repo holds **no app source and no plugin source**. It does
two things:

1. `composer require`s the private plugins onto the open image (`FROM
   ghcr.io/cboxdk/cbox-billing:<tag>`), resolving them from private GitHub repos over
   `type:vcs`.
2. Carries the production config (`.env.production.example`).

## Building the composed image

```bash
docker build \
  --secret id=composer_auth,src=auth.json \
  --build-arg BASE_TAG=latest \
  -t ghcr.io/cboxdk/cbox-billing-cloud:$(git rev-parse --short HEAD) .
```

Or via the cloud repo's build-image workflow, which `FROM`s the base tag, overlays
the plugins with the secret, and pushes the cloud image.

### The `composer_auth` secret

The only build-time secret is a **read-only GitHub token** (fine-grained, Contents:
read on the `cboxdk/cbox-billing-*` plugin repos; or a classic token with `repo`
scope). It is provided as the `composer_auth` BuildKit secret — mounted only for the
`composer require` layer, **never written into a layer**. No private Composer
registry is needed.

## What it composes

Five feature-gated plugins: reseller, revrec, connectors, tax-plus, marketplace. Each
is deny-by-default and stays inert until wired and entitled. See
[Commercial plugins](../open-core/commercial-plugins.md).

## Plugin pinning

The plugins carry no tags yet, so the Dockerfile pins each plugin's default branch
(`dev-main`) via a per-plugin `*_VERSION` ARG — reproducible per build, not immutable.
Switch to `^0.1` carets once the plugins cut releases.

## Deploy-time behaviour

The composed image runs the same base entrypoint (package discovery + caching +
php-fpm/nginx). Migration at deploy time (`composer deploy`) automatically applies the
**app's plus every installed plugin's** migrations — reseller rollup tables, revrec
deferred-revenue schedules, connectors sync ledger, tax-plus prepared filings —
alongside the engine's ledger/event-log tables (`loadMigrationsFrom`).

## Related documentation

- [Open core → Composition](../open-core/composition.md)
- [Production checklist](production-checklist.md)
- [Open core → Capability gating](../open-core/capability-gating.md)
