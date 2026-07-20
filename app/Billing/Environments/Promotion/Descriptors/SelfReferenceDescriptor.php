<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Descriptors;

/**
 * A pointer from a config object to ANOTHER object of a kind that only exists once every row has
 * been written — a plan's `default_successor_plan_id` (→ another plan) or an experiment's
 * `promoted_variant_id` (→ one of its own variants). These are rewired in a SECOND pass after the
 * whole type has been upserted, resolved to the TARGET plane's id by the referenced object's
 * natural key; an unresolved pointer (the target is absent and unselected) is left null rather
 * than blocking, because it is always a nullable, optional relationship.
 *
 * Exactly one of `type` (a top-level object type, matched in the target plane) or `childRelation`
 * (a child of THIS same object, matched within it) is set.
 */
readonly class SelfReferenceDescriptor
{
    public function __construct(
        public string $attribute,
        public ?string $type = null,
        public ?string $childRelation = null,
    ) {}
}
