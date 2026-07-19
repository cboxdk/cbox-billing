<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Billing\Payments\Dunning\CardUpdate;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * Proves and normalizes an inbound card / account-updater webhook into a {@see CardUpdate}.
 * The mirror of the engine's settlement {@see WebhookVerifier},
 * for the payment-method events the engine's settlement verifier does not model (it only knows
 * `payment.*`). Deny-by-default: an unsigned, forged, or unrecognized payload throws
 * {@see WebhookVerificationFailed} — an unverified push never becomes a {@see CardUpdate}.
 */
interface VerifiesCardUpdates
{
    /** @throws WebhookVerificationFailed */
    public function verify(WebhookPayload $payload): CardUpdate;
}
