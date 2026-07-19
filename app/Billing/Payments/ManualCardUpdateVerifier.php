<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use App\Billing\Payments\Dunning\CardUpdate;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * Verifies card / account-updater webhooks for the manual gateway (and the fully-testable path
 * for the deployable app). The operator — or any adapter that lacks a native card-updater — posts
 * a JSON body signed with the shared webhook secret over HMAC-SHA256; this proves the signature
 * with a constant-time comparison, then normalizes it into a {@see CardUpdate}.
 *
 * Deny-by-default: no secret, a missing/wrong signature, or an unparseable / non-card-update
 * body all refuse with {@see WebhookVerificationFailed}. The HMAC is delegated to PHP's
 * `hash_hmac` — no bespoke crypto.
 *
 * Expected body: `{event_id, type, account, payment_method_id, brand?, last4?, exp_month?,
 * exp_year?, source?}` where `type` is one of `payment_method.updated` |
 * `payment_method.automatically_updated` | `card.updated`.
 */
readonly class ManualCardUpdateVerifier implements VerifiesCardUpdates
{
    private const TYPES = ['payment_method.updated', 'payment_method.automatically_updated', 'card.updated'];

    public function __construct(
        private ?string $secret,
        private string $signatureHeader,
        private string $gateway = 'manual',
    ) {}

    public function verify(WebhookPayload $payload): CardUpdate
    {
        if ($this->secret === null || $this->secret === '') {
            throw WebhookVerificationFailed::noVerifierConfigured();
        }

        $signature = $payload->header($this->signatureHeader);
        $expected = hash_hmac('sha256', $payload->body, $this->secret);

        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
            throw WebhookVerificationFailed::unsigned();
        }

        return $this->normalize($payload->body);
    }

    private function normalize(string $body): CardUpdate
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw WebhookVerificationFailed::unsigned();
        }

        $eventId = $data['event_id'] ?? null;
        $type = $data['type'] ?? null;
        $account = $data['account'] ?? null;
        $paymentMethodId = $data['payment_method_id'] ?? null;

        if (! is_string($eventId) || ! is_string($type) || ! is_string($account) || ! is_string($paymentMethodId)
            || ! in_array($type, self::TYPES, true)) {
            throw WebhookVerificationFailed::unsigned();
        }

        $source = $data['source'] ?? null;

        return new CardUpdate(
            eventId: $eventId,
            gateway: $this->gateway,
            account: $account,
            paymentMethodId: $paymentMethodId,
            brand: is_string($data['brand'] ?? null) ? $data['brand'] : null,
            last4: is_scalar($data['last4'] ?? null) ? (string) $data['last4'] : null,
            expMonth: is_numeric($data['exp_month'] ?? null) ? (int) $data['exp_month'] : null,
            expYear: is_numeric($data['exp_year'] ?? null) ? (int) $data['exp_year'] : null,
            source: $type === 'payment_method.automatically_updated' || $source === CardUpdate::SOURCE_NETWORK_UPDATER
                ? CardUpdate::SOURCE_NETWORK_UPDATER
                : CardUpdate::SOURCE_CUSTOMER,
        );
    }
}
