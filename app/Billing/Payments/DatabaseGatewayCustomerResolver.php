<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\CreatesGatewayCustomer;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\GatewayCustomer;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;

/**
 * The durable, create-once implementation of {@see ResolvesGatewayCustomer}. It reads the
 * active gateway's name from the bound {@see PaymentGateway} and keys the mapping on
 * `(organization, gateway)` so switching gateways never crosses vaults.
 *
 * First resolve for a pair: mint the customer through {@see CreatesGatewayCustomer} and
 * persist it. The persist is a `firstOrCreate` on the unique `(organization_id, gateway)`
 * pair, so a race between two concurrent first-intents settles on ONE stored mapping
 * (the loser's freshly-minted id is simply not stored) rather than two divergent gateway
 * customers. Every later resolve returns the stored id without touching the gateway.
 */
readonly class DatabaseGatewayCustomerResolver implements ResolvesGatewayCustomer
{
    public function __construct(
        private PaymentGateway $gateway,
        private CreatesGatewayCustomer $factory,
    ) {}

    public function resolve(Organization $organization): string
    {
        $gateway = $this->gateway->name();

        $existing = GatewayCustomer::query()
            ->where('organization_id', $organization->id)
            ->where('gateway', $gateway)
            ->first();

        if ($existing instanceof GatewayCustomer) {
            return $existing->gateway_customer_id;
        }

        $customerId = $this->factory->create($organization, $gateway);

        $record = GatewayCustomer::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'gateway' => $gateway],
            ['gateway_customer_id' => $customerId],
        );

        return $record->gateway_customer_id;
    }
}
