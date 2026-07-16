# syntax=docker/dockerfile:1
# Image for cboxdk/cbox-billing — the open, source-available Laravel billing app,
# built FROM the public cbox php-fpm-nginx base image. Built + pushed by
# .github/workflows/build-image.yml on GitHub-hosted runners (this repo is public).
#
# No build secrets: every composer dependency is public on Packagist (incl.
# cboxdk/laravel-billing, laravel-billing-stripe, laravel-console-kit, cboxdk/license,
# laravel-health, laravel-telemetry), and the base image is a public GHCR package. The
# committed composer.lock resolves them to their published releases, so a clean checkout
# builds with a plain `composer install`. This is the image cbox-billing-cloud overlays
# FROM to `composer require` its private, feature-gated plugins.

# ---- build stage: composer + frontend (vite) ----
# Runs on the build host's native arch; vendor/ + public/build are arch-neutral,
# so the runtime image just COPYs them.
FROM --platform=$BUILDPLATFORM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm AS build
WORKDIR /var/www/html

# PHP deps first for layer caching. --no-scripts: no app env at build time; the
# base-image entrypoint runs package:discover + config/route/event caching at
# container start.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Frontend build (Node 22 ships in the standard tier). Tailwind v4 via the vite
# plugin — a plain `vite build`. `npm install` (not `npm ci`): this repo commits no
# package-lock.json yet, so there is no lockfile to install from.
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY . .
RUN npm run build && rm -rf node_modules

# ---- runtime image ----
FROM ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html

# The base-image entrypoint caches config/routes/events + runs package:discover at
# container start; schema migrations are applied by the deployment (the same split
# cbox-id and cbox-id-cloud use), never baked into this shared image — an in-image
# `migrate` would race across replicas.
ENV APP_ENV=production \
    PHP_OPCACHE_ENABLE=1
