<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Descriptors;

/**
 * A foreign-key relationship from a config object (or one of its children) to ANOTHER top-level
 * config object, matched across environments by natural key. It drives two things:
 *
 *  - **conflict detection** — a `required` dependency whose target object is neither already
 *    present in the target plane NOR included in the same selection is a BLOCKING conflict, so
 *    the engine refuses to half-apply (e.g. promoting a plan whose product is not in/selected
 *    for the target);
 *  - **relationship remapping** — on apply the source FK (a source-plane id) is rewritten to the
 *    TARGET plane's id of the same natural-keyed object.
 *
 * A `required = false` dependency is a soft/nullable pointer (a pricing table's optional annual
 * plan, an experiment's served pricing table): it is remapped when resolvable and left null
 * otherwise, never a conflict. A null source FK is always fine.
 */
readonly class DependencyDescriptor
{
    public function __construct(
        public string $attribute,
        public string $type,
        public bool $required = true,
    ) {}
}
