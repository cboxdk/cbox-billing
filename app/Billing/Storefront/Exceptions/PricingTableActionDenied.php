<?php

declare(strict_types=1);

namespace App\Billing\Storefront\Exceptions;

use RuntimeException;

/**
 * A pricing-table authoring action refused by a domain guard (a duplicate public key). Caught by
 * the console controller and surfaced as a flash error, mirroring the catalog authoring guards.
 */
class PricingTableActionDenied extends RuntimeException
{
    public static function duplicateKey(string $key): self
    {
        return new self(sprintf('A pricing table with the key “%s” already exists.', $key));
    }
}
