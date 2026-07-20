<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Descriptors;

use Illuminate\Database\Eloquent\Model;

/**
 * A child config collection owned by a promotable object (a plan's prices, a price's tiers, a
 * seller's tax registrations, a pricing table's columns/rows, an experiment's variants). Children
 * are never selected on their own — they travel with their parent — but they carry their own
 * natural key WITHIN the parent (so an update reconciles them in place rather than duplicating),
 * their own comparable fields, their own dependencies (an entitlement → meter, a column → plan),
 * and possibly their own grandchildren (a price → tiers).
 */
readonly class ChildDescriptor
{
    /**
     * @param  string  $relation  the parent's HasMany relation name (e.g. `prices`, `tiers`)
     * @param  string  $type  a stable slug for messages/diffs (e.g. `plan-price`)
     * @param  class-string<Model>  $modelClass
     * @param  string  $parentKey  the FK column on the child pointing at its parent (e.g. `plan_id`)
     * @param  list<string>  $naturalKeyAttributes  attributes forming the child's within-parent identity
     * @param  list<string>  $compareFields  config columns compared for the diff (FKs excluded)
     * @param  list<DependencyDescriptor>  $dependencies  cross-object FKs to remap/guard
     * @param  list<ChildDescriptor>  $children  grandchildren (e.g. a price's tiers)
     * @param  list<string>  $naturalKeyDependencies  dependency attributes whose target's natural key forms part of this child's identity (e.g. an entitlement identified by its meter)
     */
    public function __construct(
        public string $relation,
        public string $type,
        public string $modelClass,
        public string $parentKey,
        public array $naturalKeyAttributes,
        public array $compareFields,
        public array $dependencies = [],
        public array $children = [],
        public array $naturalKeyDependencies = [],
    ) {}
}
