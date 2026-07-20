<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\ValueObjects;

/**
 * A BLOCKING problem the preview surfaces so an apply is refused rather than half-applied: a
 * selected object whose required dependency is neither present in the target plane nor included
 * in the same selection (a plan referencing a product that is not in/selected for the target), or
 * a named object that does not exist in the source plane. The presence of any conflict makes the
 * whole promotion refuse to write.
 */
readonly class PromotionConflict
{
    public function __construct(
        public string $object,
        public string $reason,
    ) {}

    /** A missing required dependency of a selected object. */
    public static function missingDependency(string $object, string $dependencyType, string $dependencyKey): self
    {
        return new self(
            $object,
            sprintf(
                'requires %s “%s”, which is not present in the target and is not part of this promotion — select it too, or promote it first.',
                $dependencyType,
                $dependencyKey,
            ),
        );
    }

    /** A named object that does not exist in the source plane. */
    public static function unknownObject(string $object): self
    {
        return new self($object, 'was selected but does not exist in the source environment.');
    }

    public function message(): string
    {
        return $this->object.' '.$this->reason;
    }
}
