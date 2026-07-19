<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use App\Billing\Payments\Dunning\CardUpdate;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Stripe\Webhook;
use Throwable;

/**
 * Verifies Stripe's card / account-updater webhooks and maps them to a {@see CardUpdate} — the
 * REAL Stripe path for the payment-method events the `cboxdk/laravel-billing-stripe` settlement
 * adapter does NOT model (it maps only the five `payment_intent.*` events; a `payment_method.*`
 * event falls through to a no-op there). Signature verification reuses the bundled
 * `stripe/stripe-php` `Webhook::constructEvent` (HMAC-SHA256 over the raw body + timestamp
 * tolerance) — no bespoke crypto.
 *
 * BOUNDARY (documented, honest): Stripe's automatic **account updater** — where the card
 * networks push a new expiry/number for a saved card and Stripe emits
 * `payment_method.automatically_updated` — is a LIVE Stripe feature that must be enabled on the
 * account (Settings → automatic card updates) and requires eligible network enrolment. This
 * class consumes those real events when they arrive; it cannot manufacture them. Events handled:
 * `payment_method.automatically_updated` (network updater), `payment_method.updated`,
 * `customer.source.updated`, `source.updated` (customer-initiated). Anything else is refused.
 * See docs/payments/adaptive-dunning.md → "Card / account-updater seam".
 */
readonly class StripeCardUpdateVerifier implements VerifiesCardUpdates
{
    private const NETWORK_TYPES = ['payment_method.automatically_updated'];

    private const CUSTOMER_TYPES = ['payment_method.updated', 'customer.source.updated', 'source.updated'];

    public function __construct(private string $signingSecret) {}

    public function verify(WebhookPayload $payload): CardUpdate
    {
        $signature = $payload->header('Stripe-Signature');

        if ($signature === null || $signature === '') {
            throw WebhookVerificationFailed::unsigned();
        }

        try {
            $event = Webhook::constructEvent($payload->body, $signature, $this->signingSecret);
        } catch (Throwable $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        $data = $event->toArray();
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $eventId = is_string($data['id'] ?? null) ? $data['id'] : '';

        $isNetwork = in_array($type, self::NETWORK_TYPES, true);

        if (! $isNetwork && ! in_array($type, self::CUSTOMER_TYPES, true)) {
            throw WebhookVerificationFailed::unsigned();
        }

        $object = is_array($data['data'] ?? null) && is_array($data['data']['object'] ?? null)
            ? $data['data']['object']
            : [];

        $account = is_string($object['customer'] ?? null) ? $object['customer'] : '';
        $paymentMethodId = is_string($object['id'] ?? null) ? $object['id'] : '';

        if ($account === '' || $paymentMethodId === '') {
            // A card-update we can't attribute to a customer/method is not actionable.
            throw WebhookVerificationFailed::unsigned();
        }

        // Card display fields live under `card` for a PaymentMethod, or on the object itself for
        // a legacy Card/Source.
        $card = is_array($object['card'] ?? null) ? $object['card'] : $object;

        return new CardUpdate(
            eventId: $eventId,
            gateway: 'stripe',
            account: $account,
            paymentMethodId: $paymentMethodId,
            brand: is_string($card['brand'] ?? null) ? $card['brand'] : null,
            last4: is_scalar($card['last4'] ?? null) ? (string) $card['last4'] : null,
            expMonth: is_numeric($card['exp_month'] ?? null) ? (int) $card['exp_month'] : null,
            expYear: is_numeric($card['exp_year'] ?? null) ? (int) $card['exp_year'] : null,
            source: $isNetwork ? CardUpdate::SOURCE_NETWORK_UPDATER : CardUpdate::SOURCE_CUSTOMER,
        );
    }
}
