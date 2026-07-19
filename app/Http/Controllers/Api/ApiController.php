<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Api\ApiIdentity;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Subscription;
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

    /**
     * Refuse (403) unless the token is an operator (issuer-side administration such as
     * license management is never an org-scoped SDK action); null when allowed.
     */
    protected function denyUnlessOperator(Request $request): ?JsonResponse
    {
        if (! $this->identity($request)->isOperator) {
            return new JsonResponse(
                ['error' => 'This action requires an operator token.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    /**
     * Refuse (404) when a product-bound token targets an org whose billing belongs to
     * ANOTHER product — the cross-product isolation seam for a shared instance. The
     * org's product is inferred from its subscription's plan (an org's subscription,
     * invoices, usage and portal all key off that one subscription), so no `product_id`
     * column on the org is required. An org with no subscription carries no product
     * data to leak, so it is allowed (its bare provisioning is guarded by
     * {@see denyUnlessMayActFor}). A 404 (not 403) keeps another product's org
     * unenumerable. Null when allowed.
     */
    protected function denyUnlessMayUseOrgProduct(Request $request, string $org): ?JsonResponse
    {
        $productId = $this->identity($request)->productId;

        if ($productId === null) {
            return null; // an unbound (legacy/operator-wide) token spans all products
        }

        $subscription = Subscription::query()
            ->with('plan:id,product_id')
            ->where('organization_id', $org)
            ->latest('current_period_start')
            ->first();

        $subjectProduct = $subscription?->plan?->product_id;

        if ($subjectProduct !== null && (int) $subjectProduct !== $productId) {
            return $this->notFound('Unknown organization.');
        }

        return null;
    }

    /** A 404 with an operator-visible message. */
    protected function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }
}
