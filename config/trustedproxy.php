<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | The proxy or load balancer sitting in front of the app. Cbox Billing ships a
    | Dockerfile and a Helm chart, so it effectively always runs behind an ingress —
    | and until the proxy is trusted, `X-Forwarded-For` is ignored and every request
    | appears to come from the proxy's own address. Three things quietly break:
    |
    |   * The per-IP limiters on the payment-webhook and license-activation routes
    |     collapse into ONE shared bucket, so a single source can exhaust the limiter
    |     for every gateway callback and every deployment heartbeat.
    |   * The IP recorded against a quote acceptance — the field that makes the
    |     e-signature evidentiary — is the balancer's, not the signer's.
    |   * `$request->secure()` is false behind TLS termination, which is also what
    |     `SESSION_SECURE_COOKIE` depends on.
    |
    | This lives in a CONFIG file rather than being read with `env()` in
    | `bootstrap/app.php` on purpose: `php artisan config:cache` (which the deploy
    | script runs) stops the `.env` file being loaded at all, so an `env()` call
    | outside a config file returns null in exactly the deployments that need this
    | set. Config files are evaluated while the cache is built, so this survives.
    |
    | Accepts a single address, a CIDR, a comma-separated list, or `*` when the
    | ingress is the only possible path in. There is deliberately no default:
    | trusting a proxy you do not control lets a client spoof its own address.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
