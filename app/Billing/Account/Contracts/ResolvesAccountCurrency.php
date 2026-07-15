<?php

declare(strict_types=1);

namespace App\Billing\Account\Contracts;

use App\Models\Organization;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;

/**
 * Resolves the ISO currency an account transacts in — the single currency its quotes,
 * invoices and proration all run in. The order is one-way and deny-by-default:
 *
 *  1. the engine's {@see BillingCurrencyLock} if the account has finalized an invoice
 *     (the authority once set — one-way, survives payment-method changes);
 *  2. otherwise the account's chosen {@see Organization::$billing_currency};
 *  3. otherwise the app's configured default currency.
 *
 * Kept a contract so the resolution policy is swappable and controllers/services depend
 * on the seam, never on the lock and the model directly.
 */
interface ResolvesAccountCurrency
{
    /** The currency `$organization` is (or will be) billed in. */
    public function for(Organization $organization): string;
}
