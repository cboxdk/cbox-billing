<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

/**
 * The DENY-BY-DEFAULT set of config an operator chose to promote: whole {@see PromotionGroup}s
 * and/or individual {@see PromotionObjectRef} objects. Nothing is promoted unless it is named
 * here — an empty selection promotes nothing.
 *
 * The two axes compose: selecting the `branding` group promotes every seller (and its tax
 * registrations), while additionally naming `plan:pro` pulls in that one plan on top. The engine
 * resolves this into a concrete, natural-key-matched object list against the source plane.
 *
 * @phpstan-type GroupList list<PromotionGroup>
 * @phpstan-type RefList list<PromotionObjectRef>
 */
readonly class PromotionSelection
{
    /**
     * @param  list<PromotionGroup>  $groups
     * @param  list<PromotionObjectRef>  $objects
     */
    public function __construct(
        public array $groups = [],
        public array $objects = [],
    ) {}

    /** Whether nothing at all was selected (the engine then does nothing). */
    public function isEmpty(): bool
    {
        return $this->groups === [] && $this->objects === [];
    }

    /** Whether a whole group was selected. */
    public function hasGroup(PromotionGroup $group): bool
    {
        return in_array($group, $this->groups, true);
    }

    /**
     * Build a selection from the console/CLI primitives: a list of group slugs and a list of
     * `type:key` object tokens. Unknown group slugs and malformed object tokens are dropped
     * (deny-by-default — a typo never widens the selection). Groups are de-duplicated.
     *
     * @param  list<string>  $groupSlugs
     * @param  list<string>  $objectTokens
     */
    public static function fromInput(array $groupSlugs, array $objectTokens = []): self
    {
        $groups = [];
        foreach ($groupSlugs as $slug) {
            $group = PromotionGroup::tryParse($slug);
            if ($group !== null && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        $objects = [];
        $seen = [];
        foreach ($objectTokens as $token) {
            $ref = PromotionObjectRef::parse($token);
            if ($ref !== null && ! isset($seen[$ref->token()])) {
                $seen[$ref->token()] = true;
                $objects[] = $ref;
            }
        }

        return new self($groups, $objects);
    }
}
