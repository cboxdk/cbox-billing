<?php

declare(strict_types=1);

namespace App\Billing\Wallet\Exceptions;

use RuntimeException;

/**
 * An operator wallet adjustment was refused server-side (Wave 3): a non-positive amount,
 * or a debit that would drive a pool below zero beyond its policy (only a `mayGoNegative`
 * PAYG sink may hold debt). The controller catches it and flashes the reason back — the
 * wallet is left untouched.
 */
class WalletActionDenied extends RuntimeException
{
    public static function nonPositive(): self
    {
        return new self('The adjustment amount must be a positive number of units.');
    }

    public static function insufficientBalance(int $balance, int $requested): self
    {
        return new self(sprintf('Cannot debit %d — the pool holds only %d and may not go negative.', $requested, $balance));
    }
}
