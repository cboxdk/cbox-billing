<?php

declare(strict_types=1);

namespace App\Billing\Import\ValueObjects;

use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportOutcome;

/**
 * The outcome of resolving a whole export — returned by both the dry-run PLAN and the COMMIT, so
 * a report of "what would happen" and "what happened" have one shape. It carries every
 * {@see PlannedAction} and derives the per-entity/outcome counts and the conflict list from them.
 */
readonly class ImportPlan
{
    /**
     * @param  list<PlannedAction>  $actions
     */
    public function __construct(public array $actions = []) {}

    /**
     * The per-entity, per-outcome counts: `[entityValue => [outcomeValue => n]]`.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (ImportEntityType::ordered() as $entity) {
            $counts[$entity->value] = [];
        }

        foreach ($this->actions as $action) {
            $entity = $action->entity->value;
            $outcome = $action->outcome->value;
            $counts[$entity][$outcome] = ($counts[$entity][$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The actions blocked pending operator resolution (conflicts).
     *
     * @return list<PlannedAction>
     */
    public function conflicts(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (PlannedAction $a): bool => $a->outcome === ImportOutcome::Conflict,
        ));
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }

    /**
     * The actions for one entity kind, in order.
     *
     * @return list<PlannedAction>
     */
    public function forEntity(ImportEntityType $entity): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (PlannedAction $a): bool => $a->entity === $entity,
        ));
    }

    /**
     * The conflicts serialized for storage on the run (a JSON-safe list).
     *
     * @return list<array<string, mixed>>
     */
    public function conflictsForStorage(): array
    {
        return array_map(static fn (PlannedAction $a): array => [
            'entity' => $a->entity->value,
            'source_id' => $a->sourceId,
            'label' => $a->sourceLabel,
            'message' => $a->message,
        ], $this->conflicts());
    }
}
