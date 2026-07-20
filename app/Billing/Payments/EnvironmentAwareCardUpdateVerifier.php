<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Mode\BillingContext;
use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use App\Billing\Payments\Dunning\CardUpdate;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * The plane-aware card / account-updater webhook verifier. Like {@see EnvironmentAwareWebhookVerifier}
 * for the settlement path, it resolves the Stripe signing secret at VERIFY time from the current
 * environment, in strict precedence (deny-by-default when nothing is configured):
 *
 *  1. the current plane's DB-stored Stripe webhook secret ({@see EnvironmentGatewayStore});
 *  2. the global env-var Stripe webhook secret (`billing-stripe.webhook_secret`);
 *  3. the manual-gateway HMAC secret (`billing.webhook.secret`);
 *  4. otherwise the refusing {@see NullCardUpdateVerifier}.
 *
 * Resolving per call keeps two planes with different DB secrets each verifying against their own.
 */
readonly class EnvironmentAwareCardUpdateVerifier implements VerifiesCardUpdates
{
    public function __construct(
        private EnvironmentGatewayStore $gateways,
        private BillingContext $context,
        private ?string $globalStripeSecret,
        private ?string $manualSecret,
        private string $manualSignatureHeader,
    ) {}

    public function verify(WebhookPayload $payload): CardUpdate
    {
        return $this->delegate()->verify($payload);
    }

    /** The verifier for the current plane's resolved secret (DB → global env-var → manual → deny). */
    private function delegate(): VerifiesCardUpdates
    {
        $dbSecret = $this->gateways->activeFor($this->context->environmentKey())?->webhook_secret;

        if (is_string($dbSecret) && $dbSecret !== '') {
            return new StripeCardUpdateVerifier($dbSecret);
        }

        if ($this->globalStripeSecret !== null && $this->globalStripeSecret !== '') {
            return new StripeCardUpdateVerifier($this->globalStripeSecret);
        }

        if ($this->manualSecret !== null && $this->manualSecret !== '') {
            return new ManualCardUpdateVerifier(
                secret: $this->manualSecret,
                signatureHeader: $this->manualSignatureHeader,
            );
        }

        return new NullCardUpdateVerifier;
    }
}
