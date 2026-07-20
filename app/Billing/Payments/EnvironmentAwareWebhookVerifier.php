<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Mode\BillingContext;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Cbox\Billing\Stripe\StripeApiWebhookVerifier;

/**
 * The plane-aware settlement webhook verifier. It resolves the signing secret at VERIFY time from
 * the CURRENT environment, in strict precedence (deny-by-default: no secret anywhere → the manual
 * verifier refuses):
 *
 *  1. the current plane's DB-stored Stripe webhook secret ({@see EnvironmentGatewayStore}) — so a
 *     plane configured entirely through the console/API can verify its OWN Stripe webhooks, which
 *     the global-config-only binding could not;
 *  2. the global env-var Stripe webhook secret (`billing-stripe.webhook_secret`) — the single-plane
 *     default, unchanged;
 *  3. the manual-gateway HMAC verifier (`billing.webhook.secret`) — for the keyless manual gateway.
 *
 * Resolving PER CALL (not at bind time) is what makes it environment-correct: each request has
 * already pushed its plane onto the {@see BillingContext}, so two planes with different DB secrets
 * each verify against their own — and a secret meant for one plane is rejected on another. No
 * bespoke crypto: it delegates to the vetted Stripe SDK / HMAC verifiers.
 */
readonly class EnvironmentAwareWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        private EnvironmentGatewayStore $gateways,
        private BillingContext $context,
        private ?string $globalStripeSecret,
        private ?string $manualSecret,
        private string $manualSignatureHeader,
    ) {}

    public function verify(WebhookPayload $payload): WebhookEvent
    {
        return $this->delegate()->verify($payload);
    }

    /** The verifier for the current plane's resolved secret (DB → global env-var → manual). */
    private function delegate(): WebhookVerifier
    {
        $dbSecret = $this->gateways->activeFor($this->context->environmentKey())?->webhook_secret;

        if (is_string($dbSecret) && $dbSecret !== '') {
            return new StripeApiWebhookVerifier($dbSecret);
        }

        if ($this->globalStripeSecret !== null && $this->globalStripeSecret !== '') {
            return new StripeApiWebhookVerifier($this->globalStripeSecret);
        }

        return new ManualWebhookVerifier($this->manualSecret, $this->manualSignatureHeader);
    }
}
