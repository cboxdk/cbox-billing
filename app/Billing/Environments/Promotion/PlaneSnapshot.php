<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

use App\Billing\Environments\Promotion\Descriptors\ChildDescriptor;
use App\Billing\Environments\Promotion\Descriptors\ObjectDescriptor;
use App\Billing\Mode\EnvironmentScope;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * An indexed, read-only view of ONE environment's config surface — every top-level object loaded
 * once and indexed both by primary id (to resolve a source FK to the object it points at) and by
 * stable NATURAL KEY (to match the same object across planes). Natural keys are computed here,
 * where all the cross-object context lives: a seller's key is its id with the plane prefix
 * stripped; a mail template's key folds in its seller's natural key; a plan entitlement's
 * within-parent key is its meter's natural key.
 *
 * Reads lift the global {@see EnvironmentScope} and filter the plane explicitly on the base query
 * builder (the same seam the cloner uses), so a snapshot is independent of the ambient operator
 * plane and includes archived config rows (a matched child never orphans on an archived parent).
 */
class PlaneSnapshot
{
    /** @var array<string, array<string, Model>> [type][primary-id] => row */
    private array $byId = [];

    /** @var array<string, array<string, Model>> [type][natural-key] => row */
    private array $byNaturalKey = [];

    public function __construct(
        public readonly string $environmentKey,
        private readonly ConfigSurface $surface,
    ) {
        $this->load();
    }

    /** Load and index every top-level object of the surface for this plane. */
    private function load(): void
    {
        foreach ($this->surface->objects() as $descriptor) {
            $rows = $this->readRows($descriptor->modelClass);
            $type = $descriptor->type;
            $this->byId[$type] = [];

            foreach ($rows as $row) {
                $id = $row->getKey();
                if (is_scalar($id)) {
                    $this->byId[$type][(string) $id] = $row;
                }
            }
        }

        // Natural keys are indexed in a second pass so a key that folds in a dependency's own
        // natural key (mail template → seller) can resolve against the already-indexed byId maps.
        foreach ($this->surface->objects() as $descriptor) {
            $type = $descriptor->type;
            $this->byNaturalKey[$type] = [];

            foreach ($this->byId[$type] as $row) {
                $this->byNaturalKey[$type][$this->naturalKey($descriptor, $row)] = $row;
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return iterable<Model>
     */
    private function readRows(string $modelClass): iterable
    {
        $query = $modelClass::query()->withoutGlobalScope(EnvironmentScope::class);
        $query->getQuery()->where('environment', $this->environmentKey);

        return $query->get();
    }

    /**
     * All rows of a type indexed by natural key.
     *
     * @return array<string, Model>
     */
    public function rowsOf(string $type): array
    {
        return $this->byNaturalKey[$type] ?? [];
    }

    /** The row of a type with a given natural key, or null. */
    public function find(string $type, string $naturalKey): ?Model
    {
        return $this->byNaturalKey[$type][$naturalKey] ?? null;
    }

    /** The row of a type with a given primary id, or null. */
    public function findById(string $type, string $id): ?Model
    {
        return $this->byId[$type][$id] ?? null;
    }

    /**
     * The natural key of a top-level row: each key attribute, with any attribute that is a
     * dependency FK replaced by the referenced object's OWN natural key (so the key is stable
     * across planes even though the raw FK id is not), and the seller id de-prefixed.
     */
    public function naturalKey(ObjectDescriptor $descriptor, Model $row): string
    {
        $parts = [];

        foreach ($descriptor->naturalKeyAttributes as $attribute) {
            $dependencyType = $this->dependencyTypeFor($descriptor, $attribute);

            if ($dependencyType !== null) {
                $parts[] = $this->foreignNaturalKey($dependencyType, $row->getAttribute($attribute));

                continue;
            }

            if ($attribute === 'id' && $descriptor->mintsStringId) {
                $parts[] = $this->strippedSellerId($row);

                continue;
            }

            $parts[] = self::normalize($row->getAttribute($attribute));
        }

        return implode('|', $parts);
    }

    /**
     * The within-parent natural key of a child row: its key attributes plus, for each natural-key
     * dependency, the referenced top-level object's own natural key (an entitlement is identified
     * by its meter, a pricing-table column by its plan).
     */
    public function childNaturalKey(ChildDescriptor $descriptor, Model $row): string
    {
        $parts = [];

        foreach ($descriptor->naturalKeyAttributes as $attribute) {
            $parts[] = self::normalize($row->getAttribute($attribute));
        }

        foreach ($descriptor->naturalKeyDependencies as $attribute) {
            $type = $this->dependencyTypeFor($descriptor, $attribute);
            $parts[] = $type !== null
                ? $this->foreignNaturalKey($type, $row->getAttribute($attribute))
                : self::normalize($row->getAttribute($attribute));
        }

        return $parts === [] ? '' : implode('|', $parts);
    }

    /**
     * The natural key of the object a FK points at, in THIS plane, or null when the FK is
     * null/blank or points at a row that is not present. Used by the engine to resolve a
     * dependency both for conflict detection and for remapping to the target plane's id.
     */
    public function dependencyNaturalKey(string $type, mixed $fkId): ?string
    {
        if ($fkId === null || $fkId === '' || ! is_scalar($fkId)) {
            return null;
        }

        $descriptor = $this->surface->forType($type);
        $row = $descriptor !== null ? $this->findById($type, (string) $fkId) : null;

        return $descriptor !== null && $row !== null ? $this->naturalKey($descriptor, $row) : null;
    }

    /** The natural key of the object a FK points at, in THIS plane (or a stable fallback). */
    private function foreignNaturalKey(string $type, mixed $fkId): string
    {
        if ($fkId === null || $fkId === '') {
            return 'none';
        }

        $descriptor = $this->surface->forType($type);
        $row = $descriptor !== null ? $this->findById($type, (string) (is_scalar($fkId) ? $fkId : '')) : null;

        if ($descriptor === null || $row === null) {
            return 'ref:'.self::normalize($fkId);
        }

        return $this->naturalKey($descriptor, $row);
    }

    /** A seller's id with this plane's `{env}__` clone prefix stripped, so it matches across planes. */
    private function strippedSellerId(Model $row): string
    {
        $id = $row->getKey();
        $id = is_scalar($id) ? (string) $id : '';
        $prefix = $this->environmentKey.'__';

        return str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id;
    }

    /**
     * The dependency type a descriptor declares for an attribute, or null when the attribute is a
     * plain value (not a cross-object FK).
     */
    private function dependencyTypeFor(ObjectDescriptor|ChildDescriptor $descriptor, string $attribute): ?string
    {
        foreach ($descriptor->dependencies as $dependency) {
            if ($dependency->attribute === $attribute) {
                return $dependency->type;
            }
        }

        return null;
    }

    /** Normalize any attribute value to a stable string for comparison and key building. */
    public static function normalize(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }

        if (is_array($value)) {
            return (string) json_encode($value);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
