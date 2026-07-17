<?php

declare(strict_types=1);

namespace App\Billing\Catalog\Exceptions;

use Cbox\Billing\Catalog\Pricing\TierCalculator;
use RuntimeException;

/**
 * Raised when an authored plan price would not price correctly — a tier set the engine's
 * {@see TierCalculator} would reject, or a missing target.
 * The catalog controller catches it and flashes the message back to the form, so a bad
 * price is never persisted (deny-by-default, mirroring the engine's own guard).
 */
class CatalogAuthoringException extends RuntimeException
{
    public static function unknownPlan(int $planId): self
    {
        return new self(sprintf('Plan [%d] does not exist.', $planId));
    }

    public static function emptyTiers(): self
    {
        return new self('A tiered price needs at least one tier.');
    }

    public static function finalTierMustBeUnbounded(): self
    {
        return new self('The last tier must be unbounded (leave its "up to" empty) so every quantity is priced.');
    }

    public static function boundsMustAscend(): self
    {
        return new self('Tier bounds must be positive and strictly ascending, with only the final tier unbounded.');
    }

    public static function negativeAmount(): self
    {
        return new self('Tier unit and flat amounts cannot be negative.');
    }

    public static function packageNeedsSize(): self
    {
        return new self('A package price needs a positive package size.');
    }

    public static function packageNeedsBlockPrice(): self
    {
        return new self('A package price needs a flat block price on its first tier.');
    }

    /** The engine rejected the tier set; surface its reason verbatim. */
    public static function malformed(string $reason): self
    {
        return new self($reason);
    }
}
