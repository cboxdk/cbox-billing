<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /api/v1/organizations/{org}` — idempotent upsert of the organization a merchant
 * platform bills for. A SaaS consumer provisions the org on demand (tenant signup)
 * before its first subscribe/checkout call, and may re-send the same payload freely.
 *
 * `{org}` is the CALLER'S OWN tenant key, matched against `external_ref` within the acting
 * plane (an existing org's opaque id is accepted too, so a caller holding either can address
 * it). The org's own id is a generated ULID: it used to BE the caller's key, which made it
 * guessable on the unauthenticated paywall endpoint AND globally unique across planes — so the
 * same tenant key could never be provisioned in production after being used in a sandbox, which
 * surfaced as an unhandled 500 on an endpoint documented as freely repeatable. `external_ref` is
 * unique per plane instead, which is what a sandbox needs.
 *
 * `billing_currency` participates in the one-way currency lock, so it is only ever applied on
 * CREATE — an existing org's currency is never rewritten from this endpoint.
 */
class OrganizationController extends ApiController
{
    public function upsert(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'billing_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'billing_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $existing = Organization::resolveRef($org);

        if ($existing instanceof Organization) {
            // `billing_currency` is never rewritten here — the one-way currency lock.
            $existing->name = $request->string('name')->toString();
            $this->applyOptionalProfile($request, $existing);
            $existing->save();

            return new JsonResponse(['organization' => $this->present($existing)], Response::HTTP_OK);
        }

        $organization = new Organization;
        // The id is minted as a ULID by the model; the caller's key is the lookup ref.
        $organization->external_ref = $org;
        $organization->name = $request->string('name')->toString();
        $this->applyOptionalProfile($request, $organization);
        if ($request->filled('billing_currency')) {
            $organization->billing_currency = strtoupper($request->string('billing_currency')->toString());
        }
        $organization->save();

        return new JsonResponse(['organization' => $this->present($organization)], Response::HTTP_CREATED);
    }

    /**
     * Apply the mutable, nullable profile fields (email/country) when the caller sent them.
     * `billing_currency` is deliberately excluded — it is currency-locked on create only.
     */
    private function applyOptionalProfile(Request $request, Organization $organization): void
    {
        if ($request->has('billing_email')) {
            $organization->billing_email = $request->filled('billing_email')
                ? $request->string('billing_email')->toString()
                : null;
        }

        if ($request->has('billing_country')) {
            $organization->billing_country = $request->filled('billing_country')
                ? $request->string('billing_country')->toString()
                : null;
        }
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            // The caller's own key, echoed back so an integrator can join our opaque id to
            // whatever they already store without keeping a second lookup of their own.
            'external_ref' => $organization->external_ref,
            'name' => $organization->name,
            'billing_email' => $organization->billing_email,
            'billing_country' => $organization->billing_country,
            'billing_currency' => $organization->billing_currency,
        ];
    }
}
