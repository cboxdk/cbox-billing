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
    | Accepts a single address, a CIDR, or a comma-separated list.
    |
    | DO NOT USE `*`. It trusts every hop, so Symfony returns the LEFTMOST
    | `X-Forwarded-For` entry — which the client writes. That inverts the whole
    | point: instead of one shared rate-limit bucket, a single source rotates
    | through unlimited attacker-chosen buckets, and the IP recorded against a
    | quote acceptance — the field that makes the e-signature evidentiary —
    | becomes attacker-supplied. Naming the actual proxy range makes Symfony walk
    | in from the right and stop at the first untrusted hop, which is the real
    | client address as your ingress saw it.
    |
    | The default below is the private ranges (RFC 1918 plus loopback), which is
    | correct for the usual Docker/Kubernetes shape where the ingress controller
    | shares a private network with the app and the public internet cannot reach
    | the pod directly. Narrow it to your ingress CIDR if you can; widen it only
    | if you understand the consequence above.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1'),

];
