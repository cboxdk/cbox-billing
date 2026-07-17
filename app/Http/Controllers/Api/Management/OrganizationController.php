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
 * The org id is caller-supplied (the platform's own tenant key). `billing_currency`
 * participates in the one-way currency lock, so it is only ever applied on CREATE —
 * an existing org's currency is never rewritten from this endpoint.
 */
class OrganizationController extends ApiController
{
    public function upsert(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'billing_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'billing_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $existing = Organization::query()->find($org);

        if ($existing instanceof Organization) {
            // `billing_currency` is never rewritten here — the one-way currency lock.
            $existing->name = $request->string('name')->toString();
            $this->applyOptionalProfile($request, $existing);
            $existing->save();

            return new JsonResponse(['organization' => $this->present($existing)], Response::HTTP_OK);
        }

        $organization = new Organization;
        $organization->id = $org;
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
            'name' => $organization->name,
            'billing_email' => $organization->billing_email,
            'billing_country' => $organization->billing_country,
            'billing_currency' => $organization->billing_currency,
        ];
    }
}
