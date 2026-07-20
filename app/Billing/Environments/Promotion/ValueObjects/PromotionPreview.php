<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\ValueObjects;

use App\Billing\Environments\Promotion\Enums\ChangeStatus;

/**
 * The full, write-free preview of a promotion: the source and target environment keys, the diff
 * for every selected object ({@see ObjectChange}), and any blocking {@see PromotionConflict}s.
 * The console renders this for the operator to review, and the CLI `--dry-run` prints it; an
 * apply recomputes an identical plan and refuses if {@see hasConflicts()}.
 *
 * @phpstan-type ChangeList list<ObjectChange>
 * @phpstan-type ConflictList list<PromotionConflict>
 */
readonly class PromotionPreview
{
    /**
     * @param  list<ObjectChange>  $changes
     * @param  list<PromotionConflict>  $conflicts
     */
    public function __construct(
        public string $source,
        public string $target,
        public array $changes = [],
        public array $conflicts = [],
    ) {}

    /** Whether a blocking conflict was found — an apply must refuse. */
    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** Whether applying would write anything (any created/updated object). */
    public function hasWrites(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->writes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The changes with a given status, in order.
     *
     * @return list<ObjectChange>
     */
    public function withStatus(ChangeStatus $status): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (ObjectChange $c): bool => $c->status === $status,
        ));
    }

    public function createdCount(): int
    {
        return count($this->withStatus(ChangeStatus::Created));
    }

    public function updatedCount(): int
    {
        return count($this->withStatus(ChangeStatus::Updated));
    }

    public function unchangedCount(): int
    {
        return count($this->withStatus(ChangeStatus::Unchanged));
    }
}
