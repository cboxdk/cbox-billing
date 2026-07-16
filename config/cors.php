<?php

declare(strict_types=1);

/*
 * Cross-Origin Resource Sharing (CORS).
 *
 * Deny-by-default: the allowed origins are an explicit env-driven allow-list, never the
 * `*` wildcard. `CORS_ALLOWED_ORIGINS` is a comma-separated list of the exact origins
 * permitted to call the API + hosted surfaces from a browser (e.g. a product's own
 * dashboard embedding the payment element); with the variable unset, no cross-origin
 * browser request is allowed. Server-to-server SDK traffic (the enforcement API) carries
 * a bearer token and is not subject to CORS at all.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
), static fn (string $origin): bool => $origin !== ''));

return [

    'paths' => ['api/*', 'billing/*', 'webhooks/*'],

    'allowed_methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', '')),
    ), static fn (string $pattern): bool => $pattern !== '')),

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 3600),

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
