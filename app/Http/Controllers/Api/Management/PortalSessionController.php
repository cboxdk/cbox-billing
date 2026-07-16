<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/portal-sessions` {org, return_url} — open a hosted customer-portal session
 * and return the `{url}` where the customer manages its subscription, payment method and
 * invoices (ADR-0009 Path A). Per-org scoped (a token for org A cannot open a portal for
 * org B → 403). Thin: validate, authorize, delegate to {@see ManagesBillingSessions}.
 */
class PortalSessionController extends ApiController
{
    public function __invoke(Request $request, ManagesBillingSessions $sessions): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
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

        $session = $sessions->openPortal($organization, $request->string('return_url')->toString());

        return new JsonResponse([
            'url' => route('hosted.portal.show', $session->token),
            'expires_at' => $session->expires_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
