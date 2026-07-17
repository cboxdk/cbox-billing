---
title: The base Docker image
description: The open ghcr.io/cboxdk/cbox-billing image — a secret-free multi-stage build on the cbox php-fpm-nginx base, why schema migration is left to the deployment, and the composer deploy step.
weight: 71
---

# The base Docker image

The open app ships as `ghcr.io/cboxdk/cbox-billing`, built by CI on GitHub-hosted
runners from this public repo. It is the image the private
[cloud composition](cloud-composition.md) overlays.

## No build secrets

Every Composer dependency is public on Packagist (including the engine, the Stripe
adapter, console-kit, `cboxdk/license`, health, and telemetry), and the base runtime
image is a public GHCR package. The committed `composer.lock` resolves everything to
its published releases, so a clean checkout builds with a plain `composer install` —
**no build secret required**.

## The build

A multi-stage build on the cbox `php-fpm-nginx` base image:

1. **Build stage** (on the build host's native arch): `composer install --no-dev
   --no-scripts --optimize-autoloader`, then the front-end build (`npm install` +
   `npm run build` via Vite/Tailwind), then `rm -rf node_modules`. `vendor/` and
   `public/build` are arch-neutral, so the runtime image just copies them.
2. **Runtime image**: copies the built tree owned by `www-data`.

`--no-scripts` at build time because there is no app env then; the base-image
entrypoint runs `package:discover` and caches config/routes/events at **container
start**.

## Schema is not baked in

The image does **not** run migrations. Schema is applied by the **deployment** (the
same split cbox-id / cbox-id-cloud use) — an in-image `migrate` would race across
replicas. Apply it at deploy time with `composer deploy` (or
`php artisan migrate --force`). See [Operations](operations.md).

## Runtime env

Runtime configuration is supplied by the deployment (see the
[environment reference](../configuration/environment.md) and the cloud repo's
`.env.production.example`). The image sets `APP_ENV=production` and enables OPcache.

## The deploy step

`composer deploy` runs migrate + config/route/view cache + `cbox-id:publish-manifest`
(see [Deployment overview](_index.md)). Run it as your release/init step, once, not
per replica.

## Related documentation

- [Cloud composition](cloud-composition.md)
- [Production checklist](production-checklist.md)
- [Operations](operations.md)
