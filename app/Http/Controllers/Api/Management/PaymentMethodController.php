<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Api\CursorPaginator;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The saved-method surface of the embedded-intent API (ADR-0009 Path B), scoped to the
 * org's gateway customer:
 *
 *  - `GET  /api/v1/payment-methods/{org}`         — list the vaulted cards.
 *  - `POST /api/v1/payment-methods/{org}/default` — make one the off-session default.
 *  - `DELETE /api/v1/payment-methods/{org}/{id}`  — detach one from the vault.
 *
 * The listed fields are the non-sensitive display fields only (brand/last4/expiry) — the
 * gateway owns the vault and the engine never holds card data. Per-org scoped (403). Thin
 * controllers over the bound {@see PaymentGateway} plus the app's detach seam and the
 * gateway-customer mapping.
 */
class PaymentMethodController extends ApiController
{
    public function index(Request $request, string $org, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        // The gateway owns the vault and hands back the whole set of value objects, so this
        // pages the materialised list with an opaque offset cursor (same envelope as the
        // query-backed lists) rather than a keyset column.
        $page = CursorPaginator::fromList(
            $gateway->paymentMethods($customers->resolve($organization)),
            $request,
        );

        return new JsonResponse($page->envelope($this->present(...)));
    }

    public function setDefault(Request $request, string $org, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'string'],
        ]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        $account = $customers->resolve($organization);
        $gateway->setDefaultPaymentMethod($account, $request->string('id')->toString());

        $methods = array_map($this->present(...), $gateway->paymentMethods($account));

        return new JsonResponse(['data' => $methods]);
    }

    public function destroy(Request $request, string $org, string $id, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        $gateway->detachPaymentMethod($customers->resolve($organization), $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The non-sensitive display shape of a saved method — never the PAN, never the CVC.
     *
     * @return array{id: string, brand: string, last4: string, exp_month: ?int, exp_year: ?int, is_default: bool}
     */
    private function present(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'brand' => $method->brand,
            'last4' => $method->last4,
            'exp_month' => $method->expMonth,
            'exp_year' => $method->expYear,
            'is_default' => $method->isDefault,
        ];
    }
}
