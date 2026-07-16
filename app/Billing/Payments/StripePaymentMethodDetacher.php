<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\DetachesPaymentMethod;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

/**
 * Detaches a saved card from a Stripe customer's vault — the one stored-method operation
 * the engine's Stripe adapter (v0.3.0) does not expose, owned here over the same Stripe
 * SDK. Detaching a method removes it from every customer it was attached to, so the
 * `$account` is not needed by Stripe; it is kept in the signature for the seam's shape and
 * for auditing. A gateway failure surfaces rather than pretending the method is gone.
 */
readonly class StripePaymentMethodDetacher implements DetachesPaymentMethod
{
    public function __construct(private StripeClient $client) {}

    public function detach(string $account, string $paymentMethodId): void
    {
        try {
            $this->client->paymentMethods->detach($paymentMethodId);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Could not detach payment method [%s].', $paymentMethodId),
                previous: $e,
            );
        }
    }
}
