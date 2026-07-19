<?php

declare(strict_types=1);

namespace App\Billing\Wallet\Contracts;

use App\Billing\Wallet\Exceptions\WalletActionDenied;
use App\Models\WalletAdjustment;
use Cbox\Billing\Wallet\Contracts\Wallet;

/**
 * Operator wallet adjustments (Wave 3): a promotional/goodwill credit grant or a
 * correcting debit, written through the engine {@see Wallet}
 * (never a loose balance edit) and recorded as an immutable {@see WalletAdjustment} audit
 * row. Guardrails are enforced here, not on the confirm dialog.
 */
interface AdjustsWallet
{
    /**
     * Grant `$amount` credits into `$pool` for `$org` (a positive lot), optionally
     * expiring `$expiresInDays` out. Records the audit row and returns it.
     *
     * @throws WalletActionDenied when the amount is non-positive.
     */
    public function grant(string $org, string $pool, string $denomination, int $amount, string $reason, ?string $actor, ?int $expiresInDays = null): WalletAdjustment;

    /**
     * Debit `$amount` credits from `$pool` for `$org` (an offsetting negative lot).
     * Refuses a debit that would drive a pool that may not go negative below zero.
     *
     * @throws WalletActionDenied when the amount is non-positive or the balance is too low.
     */
    public function debit(string $org, string $pool, string $denomination, int $amount, string $reason, ?string $actor): WalletAdjustment;
}
