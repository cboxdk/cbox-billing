<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * Verifies settlement webhooks for the manual gateway. The operator (or a provider
 * adapter) posts a JSON body signed with a shared secret over HMAC-SHA256; this proves
 * the signature with a constant-time comparison, then normalizes the body into the
 * engine's {@see WebhookEvent}.
 *
 * Deny-by-default, per the {@see WebhookVerifier} contract: no configured secret, a
 * missing or wrong signature, or an unparseable/unknown event shape all refuse with
 * {@see WebhookVerificationFailed} — an unverified payload never becomes an event.
 *
 * The HMAC itself is delegated to PHP's `hash_hmac` (a vetted primitive) — no bespoke
 * crypto is hand-rolled here.
 *
 * Expected body: `{event_id, type, reference, amount_minor, currency}` where `type` is
 * one of `payment.settled` | `payment.failed` | `payment.pending`.
 */
readonly class ManualWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        private ?string $secret,
        private string $signatureHeader,
    ) {}

    public function verify(WebhookPayload $payload): WebhookEvent
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

    private function normalize(string $body): WebhookEvent
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw WebhookVerificationFailed::unsigned();
        }

        $eventId = $data['event_id'] ?? null;
        $type = $data['type'] ?? null;
        $reference = $data['reference'] ?? null;
        $amountMinor = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;

        if (! is_string($eventId) || ! is_string($type) || ! is_string($reference)
            || ! is_numeric($amountMinor) || ! is_string($currency)) {
            throw WebhookVerificationFailed::unsigned();
        }

        $eventType = WebhookEventType::tryFrom($type);

        if ($eventType === null) {
            throw WebhookVerificationFailed::unsigned();
        }

        return new WebhookEvent(
            id: $eventId,
            type: $eventType,
            reference: $reference,
            amount: Money::ofMinor((int) $amountMinor, $currency),
        );
    }
}
