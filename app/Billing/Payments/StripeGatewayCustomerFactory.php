<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\CreatesGatewayCustomer;
use App\Models\Organization;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

/**
 * Mints a real Stripe customer (`cus_…`) for an organization. The engine's Stripe adapter
 * (v0.3.0) creates intents and manages methods against an existing customer but does not
 * expose customer creation, so this app-layer factory owns that one call over the same
 * Stripe SDK the adapter uses — no HTTP is hand-rolled. The cbox-id org id is stamped in
 * the customer metadata so the Stripe dashboard reconciles back to the tenant.
 *
 * An SDK failure surfaces as a {@see RuntimeException} rather than a silent empty id — a
 * customer that was never created must not be stored as the org's mapping.
 */
readonly class StripeGatewayCustomerFactory implements CreatesGatewayCustomer
{
    public function __construct(private StripeClient $client) {}

    public function create(Organization $organization, string $gateway): string
    {
        try {
            $customer = $this->client->customers->create([
                'name' => $organization->name,
                'email' => $organization->billing_email ?? '',
                'metadata' => ['organization' => $organization->id],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Could not create a %s customer for organization [%s].', $gateway, $organization->id),
                previous: $e,
            );
        }

        return $customer->id;
    }
}
