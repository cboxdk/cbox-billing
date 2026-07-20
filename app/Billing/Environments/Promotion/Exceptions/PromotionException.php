<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Exceptions;

use App\Billing\Environments\Promotion\ValueObjects\PromotionConflict;
use RuntimeException;

/**
 * Raised when a promotion is refused. Promotion is deny-by-default and never half-applies: it
 * rejects an unknown/identical source & target pair, an empty selection at apply time, and — the
 * important one — any apply whose preview surfaced blocking dependency conflicts. Carrying the
 * conflicts lets the caller (console/CLI) report exactly what must be selected or promoted first.
 */
class PromotionException extends RuntimeException
{
    /** @var list<PromotionConflict> */
    public array $conflicts = [];

    public static function sameEnvironment(string $key): self
    {
        return new self("Cannot promote “{$key}” into itself — pick a different source and target.");
    }

    public static function unknownEnvironment(string $key): self
    {
        return new self("Unknown environment “{$key}”.");
    }

    public static function nothingSelected(): self
    {
        return new self('Nothing was selected to promote — choose at least one group or object.');
    }

    /**
     * @param  list<PromotionConflict>  $conflicts
     */
    public static function blockingConflicts(array $conflicts): self
    {
        $summary = implode(' ', array_map(static fn (PromotionConflict $c): string => $c->message(), $conflicts));
        $exception = new self('Promotion blocked by unresolved dependency conflicts: '.$summary);
        $exception->conflicts = $conflicts;

        return $exception;
    }
}
