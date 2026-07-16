<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/setup-intents` {org} — create a gateway SetupIntent so a product's own
 * frontend can save a card OFF-SESSION against the gateway's element (ADR-0009 Path B).
 * No charge is made; the returned client secret is what the element confirms against.
 *
 * Per-org scoped (a token for org A cannot open an intent for org B → 403). Thin:
 * validate, authorize, resolve the org's gateway customer, delegate to the bound
 * {@see PaymentGateway}, map the result.
 */
class SetupIntentController extends ApiController
{
    public function __invoke(Request $request, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        $result = $gateway->createSetupIntent(new SetupIntentRequest(
            account: $customers->resolve($organization),
            idempotencyKey: 'seti_'.Str::random(24),
        ));

        return new JsonResponse([
            'gateway' => $result->gateway,
            'publishable_key' => $result->publishableKey,
            'client_secret' => $result->clientSecret,
            'status' => $result->status->value,
            'reference' => $result->reference,
        ], Response::HTTP_CREATED);
    }
}
