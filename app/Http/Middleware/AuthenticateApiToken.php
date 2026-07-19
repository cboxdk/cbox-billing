<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Api\ApiIdentity;
use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Billing\Mode\BillingContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the enforcement API. It resolves the request's bearer token to an
 * {@see ApiIdentity} through the pluggable {@see ApiTokenAuthenticator}
 * and refuses (401) when nothing authenticates it — deny-by-default. The resolved
 * identity is attached to the request so controllers can gate the body's `org` against
 * what the token is allowed to act for.
 */
readonly class AuthenticateApiToken
{
    public const ATTRIBUTE = 'billing_api_identity';

    public function __construct(
        private ApiTokenAuthenticator $authenticator,
        private BillingContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return $this->unauthorized('Missing bearer token.');
        }

        $identity = $this->authenticator->authenticate($bearer);

        if ($identity === null) {
            return $this->unauthorized('Invalid API token.');
        }

        // Push the credential's plane onto the ambient context so every scoped read/write in
        // this request is confined to it — a test token can only touch the sandbox dataset.
        $this->context->setMode($identity->mode);

        $request->attributes->set(self::ATTRIBUTE, $identity);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
