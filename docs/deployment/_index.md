---
title: Deployment
description: Deploy Cbox Billing — the open base Docker image, the private cloud composition, the production readiness checklist, the composer deploy step, and day-two operations.
weight: 70
---

# Deployment

Cbox Billing deploys as a container. The **open** base image runs the self-hostable
app from a clean checkout; the **private** cloud composition overlays the commercial
plugins. This section covers both, the production checklist, and running the app in
anger.

## In this section

| Page | What |
| --- | --- |
| [The base Docker image](docker-image.md) | `ghcr.io/cboxdk/cbox-billing` — how it is built, and the `composer deploy` step. |
| [Cloud composition](cloud-composition.md) | The `cbox-billing-cloud` overlay, the `composer_auth` secret, and what it composes. |
| [Production checklist](production-checklist.md) | The ordered readiness path — secrets, database, gateway, licensing, RBAC manifest. |
| [Operations](operations.md) | Migrations, queue workers, the scheduler, secrets, and observability/health. |

## The deploy step

Both images run migrations and caching at deploy time via the `composer deploy`
script (from `composer.json`):

```
@php artisan migrate --force
@php artisan config:cache
@php artisan route:cache
@php artisan view:cache
@php artisan cbox-id:publish-manifest
```

So a deploy applies schema (app + all installed plugin migrations), caches
config/routes/views, and publishes the RBAC manifest to Cbox ID. Schema is applied by
the deployment step, never baked into the shared image (an in-image `migrate` would
race across replicas).

## The composition split

This mirrors how `cbox-id` / `cbox-id-cloud` split: the open image is public and
secret-free; the cloud image adds the private plugins with a build secret and carries
production config. See [Open core](../open-core/_index.md).

## Related documentation

- [Open core → Composition](../open-core/composition.md)
- [Configuration → Environment](../configuration/environment.md)
- [Identity → Federated RBAC manifest](../identity/rbac-manifest.md)
