<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\ValueObjects;

/**
 * One field that differs between a source object and its target match — the old (target) value
 * and the new (source) value that would overwrite it, as human-readable strings. This is the
 * atom of the field-level diff shown for an updated object before any write happens.
 */
readonly class FieldChange
{
    public function __construct(
        public string $field,
        public string $old,
        public string $new,
    ) {}
}
