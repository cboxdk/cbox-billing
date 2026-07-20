<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Descriptors;

use App\Billing\Environments\Promotion\PromotionGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * A TOP-LEVEL, individually-promotable config object type: its group, its Eloquent model, the
 * attributes forming its stable cross-environment natural key, the fields compared for the diff,
 * its dependencies (blocking + soft), its deferred self-references, and its owned children.
 *
 * `mintsStringId` marks the seller register — the one type whose primary key is a globally-unique
 * STRING (not an auto-increment id), so a created target row's id is minted from the natural key
 * and the target plane rather than assigned by the database.
 */
readonly class ObjectDescriptor
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $naturalKeyAttributes
     * @param  list<string>  $compareFields
     * @param  list<DependencyDescriptor>  $dependencies
     * @param  list<SelfReferenceDescriptor>  $selfReferences
     * @param  list<ChildDescriptor>  $children
     */
    public function __construct(
        public string $type,
        public PromotionGroup $group,
        public string $modelClass,
        public array $naturalKeyAttributes,
        public array $compareFields,
        public array $dependencies = [],
        public array $selfReferences = [],
        public array $children = [],
        public bool $mintsStringId = false,
    ) {}
}
