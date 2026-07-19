<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use App\Billing\Export\Enums\CursorKind;

/**
 * A dataset's incremental cursor: the physical column an incremental sync orders and filters
 * by, and the {@see CursorKind} that decides how its stored watermark compares. This is
 * distinct from the business date column a user picks a range against — the cursor is the
 * internal, monotonic delivery key (an auto-increment id, or an `updated_at`) that guarantees
 * a sync exports each row once.
 */
readonly class ExportCursor
{
    public function __construct(
        public string $column,
        public CursorKind $kind,
    ) {}

    public static function id(string $column = 'id'): self
    {
        return new self($column, CursorKind::Id);
    }

    public static function timestamp(string $column = 'updated_at'): self
    {
        return new self($column, CursorKind::Timestamp);
    }

    /** Normalise a raw database value from the cursor column into its watermark string. */
    public function normalize(mixed $value): ?string
    {
        return $this->kind->normalize($value);
    }
}
