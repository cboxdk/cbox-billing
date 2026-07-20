<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Exceptions;

use RuntimeException;

/**
 * The order-form submission did not constitute a valid e-signature-by-acceptance — the full name
 * was blank or the explicit agreement box was left unchecked. Deny-by-default: no acceptance is
 * recorded and no subscription is provisioned.
 */
class SignatureRejected extends RuntimeException
{
    public static function missingName(): self
    {
        return new self('Enter your full name to accept.');
    }

    public static function notAgreed(): self
    {
        return new self('You must tick the agreement box to accept.');
    }
}
