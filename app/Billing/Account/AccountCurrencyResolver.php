<?php

declare(strict_types=1);

namespace App\Billing\Account;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;

/**
 * The default {@see ResolvesAccountCurrency}. Reads the engine's one-way currency lock
 * first (the authority once the account has finalized an invoice), then the account's
 * chosen currency, then the app default. The lock is never overridden here — once an
 * account is locked, this returns the locked currency and the engine refuses any invoice
 * in another currency at finalization.
 */
readonly class AccountCurrencyResolver implements ResolvesAccountCurrency
{
    public function __construct(
        private BillingCurrencyLock $lock,
        private string $default,
    ) {}

    public function for(Organization $organization): string
    {
        return $this->lock->lockedCurrency($organization->id)
            ?? $organization->billing_currency
            ?? $this->default;
    }

    public function default(): string
    {
        return $this->default;
    }
}
