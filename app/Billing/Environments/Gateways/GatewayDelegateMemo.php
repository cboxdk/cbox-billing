<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use Cbox\Billing\Payment\Contracts\PaymentGateway;

/**
 * A tiny request-lifetime memo of the resolved delegate gateway per plane key, so the
 * (readonly) {@see EnvironmentAwarePaymentGateway} doesn't rebuild a plane's Stripe client on
 * every payment call. Bound as a singleton; {@see forget()} drops a plane's entry after its
 * credentials change so the next resolve rebuilds it.
 */
class GatewayDelegateMemo
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function get(string $environmentKey): ?PaymentGateway
    {
        return $this->gateways[$environmentKey] ?? null;
    }

    public function put(string $environmentKey, PaymentGateway $gateway): void
    {
        $this->gateways[$environmentKey] = $gateway;
    }

    public function forget(string $environmentKey): void
    {
        unset($this->gateways[$environmentKey]);
    }
}
