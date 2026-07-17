---
title: Composition
description: How the cbox-billing-cloud overlay composes the private plugins onto the open image — type:vcs resolution from private GitHub repos with a build secret, no private registry required.
weight: 64
---

# Composition

The `cbox-billing-cloud` repo holds **no application source and no plugin source** by
design. Its whole job is to (1) `composer require` the private plugins onto the open
image and (2) carry the SaaS production config. When a plugin ships, it is one line
here — nothing in the open app changes.

## The overlay

The cloud `Dockerfile` does `FROM ghcr.io/cboxdk/cbox-billing:<tag>` (the open image,
which already carries the app code + public vendor tree), adds a Composer **`type:vcs`
entry for each private plugin's GitHub repo**, and `composer require`s the five
plugins into the existing `vendor/`. Laravel auto-discovery wires them at container
start. No source overrides, no forked `composer.json`.

## No private registry — just a token

The four/five proprietary plugins are **not** on public Packagist, but **no private
registry** (Satis / Private Packagist) is needed. They are read straight from their
private GitHub repos over `type:vcs`, authenticated by **one read-only GitHub token**:

- A fine-grained token scoped to the `cboxdk/cbox-billing-*` plugin repos
  (Contents: read) — or a classic token with the `repo` scope.
- Supplied as the **`composer_auth`** BuildKit secret (see the `auth.json.example`
  in the cloud repo).

Everything else (the engine, the Stripe adapter, console-kit, tax engine,
`cboxdk/license`, health, telemetry, the framework) is public.

## Building

```bash
docker build \
  --secret id=composer_auth,src=auth.json \
  --build-arg BASE_TAG=latest \
  -t ghcr.io/cboxdk/cbox-billing-cloud:dev .
```

The `--secret` mount is a BuildKit tmpfs — the token is mounted only for the
`composer require` layer and is **never written into an image layer**. `BASE_TAG`
selects which open base image to overlay.

## Plugin pinning

The plugins carry no tags yet, so the Dockerfile pins each plugin's default branch
(`dev-main`) via a per-plugin version ARG. This is reproducible per build (each build
locks the current branch commit) but not immutable. When the plugins cut their first
release, switch the `*_VERSION` ARGs to a `^0.1` caret.

## Adding a plugin later

One line in the cloud `Dockerfile`'s `composer require` (plus a version ARG). Nothing
in the open app changes — the plugin registers its own nav/UI/gates/migrations on
install.

## Related documentation

- [Commercial plugins](commercial-plugins.md)
- [Capability gating](capability-gating.md)
- [Deployment → Cloud composition](../deployment/cloud-composition.md)
