<?php

declare(strict_types=1);

namespace App\Billing\Retention\Exceptions;

use RuntimeException;

/**
 * A retention action that cannot be applied to the subscription in its current state — for
 * example reactivating a subscription that is neither paused, scheduled to cancel, nor
 * canceled within the win-back window. Controllers map it to a client error rather than a
 * 500, so the caller learns the subscription is not in a reactivatable state.
 */
class RetentionException extends RuntimeException
{
    public static function notReactivatable(): self
    {
        return new self('This subscription cannot be reactivated: it is not paused, scheduled to cancel, or canceled within the win-back window.');
    }
}
