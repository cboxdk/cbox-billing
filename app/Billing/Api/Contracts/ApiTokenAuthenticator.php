<?php

declare(strict_types=1);

namespace App\Billing\Api\Contracts;

use App\Billing\Api\ApiIdentity;

/**
 * Resolves a bearer token into an {@see ApiIdentity}, or `null` when it authenticates
 * nothing (deny-by-default). Kept a contract so the auth mechanism is pluggable — the
 * default resolves a configured operator token and per-org `api_tokens` rows, but a host
 * can bind its own (JWT, mTLS, an upstream gateway) without touching the middleware.
 */
interface ApiTokenAuthenticator
{
    public function authenticate(string $bearer): ?ApiIdentity;
}
