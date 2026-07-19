<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

/**
 * One streamed row: the schema-ordered, already-typed output payload plus the raw cursor
 * value of the underlying record. Carrying the cursor alongside the payload lets an
 * incremental sync advance its watermark from the true delivery key even when that key is
 * NOT part of the exported schema (the usage-event log exports its stable `event_id` but is
 * sequenced by its internal auto-increment id).
 *
 * The payload is a serialization boundary, so it is a flat map of scalar-or-null values —
 * the encoders render each entry per its column type on the way out.
 *
 * @phpstan-type RowData array<string, scalar|null>
 */
readonly class ExportRow
{
    /** @param array<string, scalar|null> $data */
    public function __construct(
        public array $data,
        public ?string $cursor = null,
    ) {}
}
