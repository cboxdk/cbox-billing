<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use Cbox\Billing\Payment\Contracts\PaymentGateway;

/**
 * Removes a saved payment method from a gateway customer's vault. The engine's
 * {@see PaymentGateway} at v0.3.0 exposes listing,
 * attaching, and defaulting methods but no detach, so the embedded-intent API owns this
 * one operation as an app seam over the same gateway — bound to the gateway's own detach
 * where it has a vault (Stripe) and a no-op where it has none (the manual gateway).
 */
interface DetachesPaymentMethod
{
    /** Detach `$paymentMethodId` from the gateway customer `$account`. */
    public function detach(string $account, string $paymentMethodId): void;
}
