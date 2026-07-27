<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonicalises an organization reference on the AUTHENTICATED API: a caller may address an org
 * by its opaque id or by their own `external_ref`, and everything downstream sees the id.
 *
 * The org id is a generated ULID, so it is no longer the tenant key an integrator already holds.
 * Forcing them to store a second identifier would be a gratuitous DX tax, and threading a
 * resolve call through every controller would leave the next endpoint to remember it — so the
 * translation happens once, here, and the rest of the API keeps treating `$org` as the id.
 *
 * Runs BEFORE the org authorization check, which compares the request's org against the token's:
 * both sides must be canonical or a token scoped to one org would be refused when the caller
 * names the same org by their own ref.
 *
 * DELIBERATELY NOT ON THE PUBLIC SURFACES. `/paywall`, the pricing table and the quote pages are
 * unauthenticated, and accepting a guessable `external_ref` there would restore exactly the
 * enumeration oracle the ULID id was introduced to close: those take the opaque id only.
 */
class ResolveOrganizationRef
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->canonicaliseRouteParameter($request);
        $this->canonicaliseInput($request);

        return $next($request);
    }

    /** `/organizations/{org}`, `/subscriptions/{org}/…` and friends. */
    private function canonicaliseRouteParameter(Request $request): void
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return;
        }

        $ref = $route->parameter('org');

        if (! is_string($ref) || $ref === '') {
            return;
        }

        $canonical = $this->canonical($ref);

        if ($canonical !== null) {
            $route->setParameter('org', $canonical);
        }
    }

    /** The enforcement endpoints carry `org` in the body rather than the path. */
    private function canonicaliseInput(Request $request): void
    {
        $ref = $request->input('org');

        if (! is_string($ref) || $ref === '') {
            return;
        }

        $canonical = $this->canonical($ref);

        if ($canonical !== null) {
            $request->merge(['org' => $canonical]);
        }
    }

    /**
     * The org's canonical id for a reference that may be either the id or an `external_ref`.
     *
     * Null when nothing resolves — an unknown reference must stay unknown so the endpoint's own
     * 404 (or its create path, on the upsert) behaves exactly as before.
     */
    private function canonical(string $ref): ?string
    {
        // An id that already resolves needs no lookup by ref; this also keeps the hot enforcement
        // path to a single indexed primary-key hit in the common case.
        $organization = Organization::query()->find($ref);

        if ($organization instanceof Organization) {
            return $organization->id;
        }

        $id = Organization::query()->where('external_ref', $ref)->value('id');

        return is_string($id) ? $id : null;
    }
}
