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

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'billing_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'billing_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $existing = Organization::query()->find($org);

        if ($existing instanceof Organization) {
            $existing->fill(collect($data)->except('billing_currency')->all())->save();

            return new JsonResponse(['organization' => $this->present($existing)], Response::HTTP_OK);
        }

        if (isset($data['billing_currency']) && is_string($data['billing_currency'])) {
            $data['billing_currency'] = strtoupper($data['billing_currency']);
        }

        $organization = Organization::query()->create(['id' => $org] + $data);

        return new JsonResponse(['organization' => $this->present($organization)], Response::HTTP_CREATED);
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
