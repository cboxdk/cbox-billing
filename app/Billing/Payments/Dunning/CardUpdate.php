<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Contracts\VerifiesCardUpdates;

/**
 * A card / account-updater push: a gateway told us a vaulted card changed — a new expiry or
 * number a card network pushed via account-updater / network tokenization, or a customer
 * swapping their method. It is the normalized, card-data-free shape a
 * {@see VerifiesCardUpdates} produces from a verified webhook
 * and the {@see UpdatesCards} seam consumes: the account the
 * method belongs to, the (new) method's gateway token and its non-sensitive display fields —
 * never the PAN, never the CVC.
 *
 * `account` is the resolvable account key the gateway keys the vault by — the gateway customer
 * id (`cus_…`) for Stripe, or the host account/organization id for the manual gateway. `source`
 * distinguishes an automatic network updater push from a customer-initiated change.
 */
readonly class CardUpdate
{
    public const SOURCE_NETWORK_UPDATER = 'network_updater';

    public const SOURCE_CUSTOMER = 'customer';

    public function __construct(
        public string $eventId,
        public string $gateway,
        public string $account,
        public string $paymentMethodId,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
        public string $source = self::SOURCE_NETWORK_UPDATER,
    ) {}

    public function isNetworkUpdater(): bool
    {
        return $this->source === self::SOURCE_NETWORK_UPDATER;
    }
}
