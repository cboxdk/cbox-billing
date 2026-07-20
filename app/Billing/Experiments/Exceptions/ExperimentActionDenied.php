<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Exceptions;

use RuntimeException;

/**
 * A guarded experiment action was refused — a duplicate key, an invalid variant set (no control,
 * empty weights), or an illegal lifecycle transition (starting a concluded experiment, promoting
 * a variant from another experiment). Carries an operator-facing message the console surfaces as
 * a flash error.
 */
class ExperimentActionDenied extends RuntimeException
{
    public static function duplicateKey(string $key): self
    {
        return new self(sprintf('An experiment with the key “%s” already exists.', $key));
    }

    public static function needsControl(): self
    {
        return new self('An experiment needs exactly one control variant.');
    }

    public static function needsVariants(): self
    {
        return new self('An experiment needs a control and at least one challenger variant.');
    }

    public static function zeroWeight(): self
    {
        return new self('The variant traffic weights must sum to more than zero.');
    }

    public static function notDraft(): self
    {
        return new self('Only a draft experiment can be started.');
    }

    public static function notRunning(): self
    {
        return new self('Only a running experiment can be concluded.');
    }

    public static function foreignWinner(): self
    {
        return new self('The promoted variant must belong to this experiment.');
    }
}
