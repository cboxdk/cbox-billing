<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Enums;

/**
 * How a selected config object compares to its match in the TARGET environment, keyed by stable
 * natural key: {@see Created} (no match in target — a new row will be inserted), {@see Updated}
 * (a match exists but some field — its own or a child's — differs, so the existing target row is
 * updated in place), or {@see Unchanged} (identical — re-promoting it is a no-op).
 */
enum ChangeStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';

    /** Whether applying this object writes anything at all (created/updated do; unchanged does not). */
    public function writes(): bool
    {
        return $this !== self::Unchanged;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
