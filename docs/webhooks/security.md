---
title: Security & SSRF
description: How outbound webhook endpoint URLs are guarded against server-side request forgery — public-only targets, DNS pinning, and no redirects.
weight: 40
---

# Security & SSRF

Adding outbound delivery introduces a **server-side request forgery (SSRF)** sink: the app makes an HTTP request to an operator/integrator-supplied URL. Without a guard, an attacker who can register an endpoint could point it at an internal address (`127.0.0.1`, `169.254.169.254`, a private `10.x`/`192.168.x` host) and have the app fetch it.

Cbox Billing guards every outbound URL with the shared, independently-tested `cboxdk/laravel-ssrf` package.

## What is enforced

- **Public unicast only.** The endpoint host must resolve to a public address. Private, loopback, link-local, and cloud-metadata ranges are refused.
- **`http`/`https` only**, with no embedded credentials in the URL.
- **DNS pinning, TOCTOU-closed.** The URL is validated at registration *and* re-validated immediately before each delivery, pinning the connection to the exact IPs just resolved. A DNS rebind between the check and the connect cannot redirect the delivery to an internal address.
- **No redirects.** A `30x` response is not followed, so a receiver cannot bounce the request to an internal host.

A URL that fails the guard is refused at registration (with a validation error) and, if it becomes unsafe later, treated as a retryable delivery failure rather than a connection to an internal host.

## Single-tenant escape hatch

A single-tenant / on-prem operator who must deliver to an internal host can disable enforcement with `CBOX_WEBHOOKS_VERIFY_URL=false`. **Keep it enabled (the default) in any multi-tenant or hosted deployment** — disabling it removes the SSRF protection for every endpoint.

## Secrets at rest

Endpoint signing secrets are stored encrypted (AES-256-GCM via the app key), decrypted only to compute a delivery's signature. The plaintext is shown once at registration/rotation and never read back into the console.
