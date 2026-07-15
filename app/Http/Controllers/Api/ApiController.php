<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Api\ApiIdentity;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared plumbing for the enforcement API controllers: reads the authenticated
 * {@see ApiIdentity} the middleware attached, and enforces that the token may act for the
 * `org` named in the request (deny-by-default — an org-scoped token can only touch its own
 * org). Controllers stay thin: validate, authorize the org, delegate to an engine-backed
 * service, map the result.
 */
abstract class ApiController extends Controller
{
    protected function identity(Request $request): ApiIdentity
    {
        $identity = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        // The middleware guarantees this is set; the guard keeps the type honest.
        return $identity instanceof ApiIdentity ? $identity : ApiIdentity::forOrganization('');
    }

    /** Refuse (403) when the token may not act for `$org`; null when allowed. */
    protected function denyUnlessMayActFor(Request $request, string $org): ?JsonResponse
    {
        if (! $this->identity($request)->mayActFor($org)) {
            return new JsonResponse(
                ['error' => 'This token is not permitted to act for the requested organization.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }
}
