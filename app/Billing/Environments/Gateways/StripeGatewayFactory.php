<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use App\Models\EnvironmentGateway;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Stripe\StripeApiIntentCreator;
use Cbox\Billing\Stripe\StripePaymentGateway;
use Stripe\StripeClient;

/**
 * Builds a real Stripe {@see PaymentGateway} from one plane's decrypted per-environment
 * credentials — the same wiring the Stripe adapter's service provider does from the global
 * env-var config, but keyed to a specific environment's secret/publishable rather than the
 * process-wide `STRIPE_SECRET`. Used by the {@see EnvironmentAwarePaymentGateway} when a plane
 * has its own active credentials, so each plane charges through its own Stripe account.
 *
 * The `StripeClient` is constructed lazily (no network at build time), so resolving the gateway
 * for a plane that never charges costs nothing. The shared durable {@see SettledPaymentStore}
 * (idempotency across processes) is reused — it is plane-scoped by its own environment column.
 */
readonly class StripeGatewayFactory
{
    public function __construct(private SettledPaymentStore $settledPayments) {}

    public function make(EnvironmentGateway $credentials): PaymentGateway
    {
        return new StripePaymentGateway(
            new StripeApiIntentCreator(new StripeClient($credentials->secret)),
            $this->settledPayments,
            $credentials->publishable ?? '',
        );
    }
}
