<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\DetachesPaymentMethod;

/**
 * The detach seam for vault-less gateways (the manual / off-line gateway). Such a gateway
 * has no card vault and reports no saved methods, so there is nothing to detach — the
 * operation is honestly a no-op rather than a call to a store that does not exist.
 */
readonly class ManualPaymentMethodDetacher implements DetachesPaymentMethod
{
    public function detach(string $account, string $paymentMethodId): void
    {
        // Vault-less gateway: no saved methods, nothing to remove.
    }
}
