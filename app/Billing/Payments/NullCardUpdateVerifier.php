<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use App\Billing\Payments\Dunning\CardUpdate;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * The deny-by-default card-update verifier: it refuses every payload. Bound when no card-update
 * source is configured (no manual webhook secret and no Stripe signing secret), so the
 * card-updater ingest route is closed rather than trusting unverified input.
 */
readonly class NullCardUpdateVerifier implements VerifiesCardUpdates
{
    public function verify(WebhookPayload $payload): CardUpdate
    {
        throw WebhookVerificationFailed::noVerifierConfigured();
    }
}
