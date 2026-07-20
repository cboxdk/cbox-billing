<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Contracts;

use App\Models\BillingSession;

/**
 * Attributes experiment conversions to the variant a checkout session carried — the seam the
 * checkout and settlement flows depend on rather than the concrete recorder. Both paths are
 * best-effort and idempotent on the unique `(variant, visitor, kind)` index, so a conversion is
 * counted at most once and a failed write never breaks a checkout or a webhook.
 */
interface AttributesConversions
{
    /** Record a checkout-started conversion from the attribution the checkout session carried. */
    public function recordCheckoutStart(BillingSession $session, string $experimentKey, int $variantId, string $visitorId): void;

    /** Record the checkout-completed conversion(s) for a settled session, idempotently. */
    public function recordSettlement(BillingSession $session): void;
}
