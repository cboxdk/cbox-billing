<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\GatewayCustomer;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Throwable;

/**
 * Read model for the customer-detail payment-methods panel. It lists an org's VAULTED
 * methods live from the bound gateway — the gateway owns the vault, so only the non-sensitive
 * display fields (brand/last4/expiry/default) ever surface, never card data. It reads the
 * EXISTING gateway-customer mapping rather than resolving one, so merely viewing a customer
 * never mints a gateway customer; an org with no mapping simply has no methods yet.
 *
 * The manual gateway vaults nothing, so its methods are read-only — the panel says so and
 * offers no remove/set-default.
 */
readonly class CustomerPaymentMethods
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @return array{gateway: string, gateway_customer_id: ?string, manual: bool, methods: list<array{id: string, brand: string, last4: string, exp: ?string, default: bool}>}
     */
    public function forOrganization(Organization $organization): array
    {
        $gatewayName = $this->gateway->name();
        $manual = $gatewayName === 'manual';

        $mapping = GatewayCustomer::query()
            ->where('organization_id', $organization->id)
            ->where('gateway', $gatewayName)
            ->first();

        $methods = [];

        if ($mapping instanceof GatewayCustomer && ! $manual) {
            try {
                $methods = array_map(
                    $this->present(...),
                    $this->gateway->paymentMethods($mapping->gateway_customer_id),
                );
            } catch (Throwable) {
                // A gateway hiccup must never break the customer page — show no methods.
                $methods = [];
            }
        }

        return [
            'gateway' => $gatewayName,
            'gateway_customer_id' => $mapping instanceof GatewayCustomer ? $mapping->gateway_customer_id : null,
            'manual' => $manual,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{id: string, brand: string, last4: string, exp: ?string, default: bool}
     */
    private function present(PaymentMethod $method): array
    {
        $exp = $method->expMonth !== null && $method->expYear !== null
            ? sprintf('%02d/%d', $method->expMonth, $method->expYear)
            : null;

        return [
            'id' => $method->id,
            'brand' => $method->brand,
            'last4' => $method->last4,
            'exp' => $exp,
            'default' => $method->isDefault,
        ];
    }
}
