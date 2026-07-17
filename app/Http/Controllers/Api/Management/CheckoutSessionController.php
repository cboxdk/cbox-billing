<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/checkout-sessions` {org, plan, currency?, return_url} — open a hosted
 * checkout session and return the `{url}` the customer completes payment at (ADR-0009
 * Path A). Per-org scoped (a token for org A cannot open a session for org B → 403); the
 * plan must be priced in the resolved currency (deny-by-default) rather than shown at a
 * fabricated rate. Thin: validate, authorize, delegate to {@see ManagesBillingSessions}.
 */
class CheckoutSessionController extends ApiController
{
    public function __invoke(Request $request, ManagesBillingSessions $sessions, ResolvesAccountCurrency $currencies): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'return_url' => ['required', 'url'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        $plan = Plan::query()->with('prices')->where('key', $request->string('plan')->toString())->first();

        // A product-bound token can only sell its own product's plans (shared instance).
        if (! $plan instanceof Plan || ! $this->identity($request)->mayUseProduct((int) $plan->product_id)) {
            return new JsonResponse(['error' => 'Unknown plan.'], Response::HTTP_NOT_FOUND);
        }

        $currency = $request->has('currency')
            ? strtoupper($request->string('currency')->toString())
            : $currencies->for($organization);

        if (! $plan->prices->contains('currency', $currency)) {
            return new JsonResponse(
                ['error' => sprintf('Plan [%s] is not priced in %s.', $plan->key, $currency)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $session = $sessions->openCheckout(
            $organization,
            $plan,
            $request->has('currency') ? $currency : null,
            $request->string('return_url')->toString(),
        );

        return new JsonResponse([
            'url' => route('hosted.checkout.show', $session->token),
            'expires_at' => $session->expires_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
