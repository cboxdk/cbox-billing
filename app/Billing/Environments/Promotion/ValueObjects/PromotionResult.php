<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\ValueObjects;

/**
 * The outcome of an applied promotion: the preview that was executed (so the caller can report
 * exactly what was published) plus the created/updated/unchanged tallies. Re-promoting an
 * unchanged selection returns a result with zero created/updated — the idempotent no-op.
 */
readonly class PromotionResult
{
    public function __construct(
        public PromotionPreview $preview,
        public int $created,
        public int $updated,
        public int $unchanged,
    ) {}

    public function source(): string
    {
        return $this->preview->source;
    }

    public function target(): string
    {
        return $this->preview->target;
    }

    /** Whether the apply wrote anything (false for an idempotent re-promotion). */
    public function wroteAnything(): bool
    {
        return $this->created > 0 || $this->updated > 0;
    }
}
