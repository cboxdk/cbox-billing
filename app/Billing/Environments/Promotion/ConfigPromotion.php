<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Environments\Promotion\Contracts\PromotesConfig;
use App\Billing\Environments\Promotion\Descriptors\ChildDescriptor;
use App\Billing\Environments\Promotion\Descriptors\DependencyDescriptor;
use App\Billing\Environments\Promotion\Descriptors\ObjectDescriptor;
use App\Billing\Environments\Promotion\Enums\ChangeStatus;
use App\Billing\Environments\Promotion\Exceptions\PromotionException;
use App\Billing\Environments\Promotion\Support\ResolvedObject;
use App\Billing\Environments\Promotion\ValueObjects\FieldChange;
use App\Billing\Environments\Promotion\ValueObjects\ObjectChange;
use App\Billing\Environments\Promotion\ValueObjects\PromotionConflict;
use App\Billing\Environments\Promotion\ValueObjects\PromotionPreview;
use App\Billing\Environments\Promotion\ValueObjects\PromotionResult;
use App\Billing\Mode\EnvironmentScope;
use App\Models\Coupon;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Publishes SELECTED config from a source environment to a target one — matching every object
 * across the two planes by stable NATURAL KEY (ids differ per plane), classifying each as
 * created / updated / unchanged, and — on apply — upserting it into the target while remapping
 * its intra-config relationships to the TARGET plane's ids.
 *
 * The guarantees:
 *  - **deny-by-default** — only objects named by the {@see PromotionSelection} are considered;
 *  - **additive, never destructive** — a matched target row is updated IN PLACE (its id and any
 *    history/relationships that reference it are preserved) and an absent one is created; a target
 *    object the selection did not include is NEVER deleted (promote is upsert, not a sync);
 *  - **no half-apply** — a selected object whose required dependency is not in/selected for the
 *    target is a blocking conflict surfaced in the preview, and an apply with any conflict refuses
 *    before writing anything;
 *  - **idempotent** — re-promoting an unchanged selection writes nothing;
 *  - **one transaction, audit-logged** — a successful apply records one `config.promoted` event.
 *
 * Never promotes transactional/tenant data (only the config surface of {@see ConfigSurface}). The
 * per-environment gateway credentials in `environment_gateways` (encrypted DB rows) are DELIBERATELY
 * EXCLUDED from the promotable surface — a promotion never copies a plane's gateway secrets, so a
 * source plane's keys can never cross into the target.
 */
class ConfigPromotion implements PromotesConfig
{
    public function __construct(
        private readonly ConfigSurface $surface,
        private readonly RecordsAudit $audit,
    ) {}

    public function preview(Environment $source, Environment $target, PromotionSelection $selection): PromotionPreview
    {
        $this->guard($source, $target);

        $sourceSnap = $this->snapshot($source->key);
        $targetSnap = $this->snapshot($target->key);

        [$objects, $conflicts] = $this->resolveSelection($selection, $sourceSnap);

        $changes = $this->buildChanges($objects, $sourceSnap, $targetSnap);
        $conflicts = [...$conflicts, ...$this->detectConflicts($objects, $sourceSnap, $targetSnap)];

        return new PromotionPreview($source->key, $target->key, $changes, $conflicts);
    }

    public function promote(Environment $source, Environment $target, PromotionSelection $selection): PromotionResult
    {
        $this->guard($source, $target);

        if ($selection->isEmpty()) {
            throw PromotionException::nothingSelected();
        }

        $preview = $this->preview($source, $target, $selection);

        if ($preview->hasConflicts()) {
            throw PromotionException::blockingConflicts($preview->conflicts);
        }

        $result = new PromotionResult(
            $preview,
            $preview->createdCount(),
            $preview->updatedCount(),
            $preview->unchangedCount(),
        );

        if (! $result->wroteAnything()) {
            return $result; // idempotent no-op — nothing to write, nothing to audit.
        }

        DB::transaction(function () use ($source, $target, $selection, $preview): void {
            $sourceSnap = $this->snapshot($source->key);
            $targetSnap = $this->snapshot($target->key);
            [$objects] = $this->resolveSelection($selection, $sourceSnap);

            $this->applyObjects($objects, $sourceSnap, $targetSnap, $target, $preview);
            $this->recordAudit($source, $target, $preview);
        });

        return $result;
    }

    /** Refuse an unknown or self-targeting promotion before any read. */
    private function guard(Environment $source, Environment $target): void
    {
        if ($source->key === '') {
            throw PromotionException::unknownEnvironment('(source)');
        }

        if ($target->key === '') {
            throw PromotionException::unknownEnvironment('(target)');
        }

        if ($source->key === $target->key) {
            throw PromotionException::sameEnvironment($source->key);
        }
    }

    private function snapshot(string $environmentKey): PlaneSnapshot
    {
        return new PlaneSnapshot($environmentKey, $this->surface);
    }

    // -----------------------------------------------------------------------------------------
    // Selection resolution
    // -----------------------------------------------------------------------------------------

    /**
     * Resolve the deny-by-default selection into a concrete, dependency-ordered object list
     * (deduplicated by type + natural key) plus any "selected object not found in source"
     * conflicts.
     *
     * @return array{0: list<ResolvedObject>, 1: list<PromotionConflict>}
     */
    private function resolveSelection(PromotionSelection $selection, PlaneSnapshot $sourceSnap): array
    {
        /** @var array<string, array<string, ResolvedObject>> $picked [type][naturalKey] => resolved */
        $picked = [];
        $conflicts = [];

        foreach ($selection->groups as $group) {
            foreach ($this->surface->forGroup($group) as $descriptor) {
                foreach ($sourceSnap->rowsOf($descriptor->type) as $naturalKey => $row) {
                    $picked[$descriptor->type][$naturalKey] = new ResolvedObject($descriptor, $row, $naturalKey);
                }
            }
        }

        foreach ($selection->objects as $ref) {
            $descriptor = $this->surface->forType($ref->type);
            if ($descriptor === null) {
                $conflicts[] = PromotionConflict::unknownObject($ref->token());

                continue;
            }

            $row = $sourceSnap->find($ref->type, $ref->key);
            if ($row === null) {
                $conflicts[] = PromotionConflict::unknownObject($ref->token());

                continue;
            }

            $picked[$descriptor->type][$ref->key] = new ResolvedObject($descriptor, $row, $ref->key);
        }

        // Emit in the surface's dependency order so an apply writes dependencies first.
        $objects = [];
        foreach ($this->surface->objects() as $descriptor) {
            foreach ($picked[$descriptor->type] ?? [] as $resolved) {
                $objects[] = $resolved;
            }
        }

        return [$objects, $conflicts];
    }

    // -----------------------------------------------------------------------------------------
    // Diff (preview) — no writes
    // -----------------------------------------------------------------------------------------

    /**
     * @param  list<ResolvedObject>  $objects
     * @return list<ObjectChange>
     */
    private function buildChanges(array $objects, PlaneSnapshot $sourceSnap, PlaneSnapshot $targetSnap): array
    {
        $changes = [];
        foreach ($objects as $object) {
            $changes[] = $this->diffObject($object, $sourceSnap, $targetSnap);
        }

        return $changes;
    }

    private function diffObject(ResolvedObject $object, PlaneSnapshot $sourceSnap, PlaneSnapshot $targetSnap): ObjectChange
    {
        $descriptor = $object->descriptor;
        $targetRow = $targetSnap->find($descriptor->type, $object->naturalKey);

        $fieldChanges = $this->compareFields($descriptor->compareFields, $object->row, $targetRow);
        $childChanges = $this->diffChildren($descriptor->children, $descriptor->group, $object->row, $targetRow, $sourceSnap, $targetSnap);

        $status = $this->statusFor($targetRow, $fieldChanges, $childChanges);

        return new ObjectChange(
            $descriptor->group,
            $descriptor->type,
            $object->naturalKey,
            $this->labelFor($object->row, $object->naturalKey),
            $status,
            $fieldChanges,
            $childChanges,
        );
    }

    /**
     * @param  list<ChildDescriptor>  $children
     * @return list<ObjectChange>
     */
    private function diffChildren(
        array $children,
        PromotionGroup $group,
        Model $sourceParent,
        ?Model $targetParent,
        PlaneSnapshot $sourceSnap,
        PlaneSnapshot $targetSnap,
    ): array {
        $changes = [];

        foreach ($children as $child) {
            $targetByKey = [];
            if ($targetParent !== null) {
                foreach ($this->readChildren($child, $targetParent) as $row) {
                    $targetByKey[$targetSnap->childNaturalKey($child, $row)] = $row;
                }
            }

            foreach ($this->readChildren($child, $sourceParent) as $sourceRow) {
                $key = $sourceSnap->childNaturalKey($child, $sourceRow);
                $targetRow = $targetByKey[$key] ?? null;

                $fieldChanges = $this->compareFields($child->compareFields, $sourceRow, $targetRow);
                $grandChildren = $this->diffChildren($child->children, $group, $sourceRow, $targetRow, $sourceSnap, $targetSnap);
                $status = $this->statusFor($targetRow, $fieldChanges, $grandChildren);

                if (! $status->writes()) {
                    continue; // an unchanged child is omitted from the preview.
                }

                $changes[] = new ObjectChange(
                    $group,
                    $child->type,
                    $key,
                    $this->labelFor($sourceRow, $key),
                    $status,
                    $fieldChanges,
                    $grandChildren,
                );
            }
        }

        return $changes;
    }

    /**
     * The field-level diff of an object against its target match (empty when there is no match —
     * a creation has no field diff, it is wholly new).
     *
     * @param  list<string>  $fields
     * @return list<FieldChange>
     */
    private function compareFields(array $fields, Model $sourceRow, ?Model $targetRow): array
    {
        if ($targetRow === null) {
            return [];
        }

        $changes = [];
        foreach ($fields as $field) {
            $old = PlaneSnapshot::normalize($targetRow->getAttribute($field));
            $new = PlaneSnapshot::normalize($sourceRow->getAttribute($field));

            if ($old !== $new) {
                $changes[] = new FieldChange($field, $this->display($old), $this->display($new));
            }
        }

        return $changes;
    }

    /**
     * @param  list<FieldChange>  $fieldChanges
     * @param  list<ObjectChange>  $childChanges
     */
    private function statusFor(?Model $targetRow, array $fieldChanges, array $childChanges): ChangeStatus
    {
        if ($targetRow === null) {
            return ChangeStatus::Created;
        }

        return $fieldChanges === [] && $childChanges === []
            ? ChangeStatus::Unchanged
            : ChangeStatus::Updated;
    }

    // -----------------------------------------------------------------------------------------
    // Conflict detection
    // -----------------------------------------------------------------------------------------

    /**
     * A blocking conflict for every required dependency (of a selected object OR one of its
     * children) whose target object is neither present in the target plane nor part of this same
     * selection — so a dependent object can never be applied against a missing dependency.
     *
     * @param  list<ResolvedObject>  $objects
     * @return list<PromotionConflict>
     */
    private function detectConflicts(array $objects, PlaneSnapshot $sourceSnap, PlaneSnapshot $targetSnap): array
    {
        /** @var array<string, array<string, true>> $selected [type][naturalKey] => true */
        $selected = [];
        foreach ($objects as $object) {
            $selected[$object->descriptor->type][$object->naturalKey] = true;
        }

        $conflicts = [];

        foreach ($objects as $object) {
            $token = $object->descriptor->type.':'.$object->naturalKey;

            foreach ($object->descriptor->dependencies as $dependency) {
                $conflict = $this->dependencyConflict($token, $dependency, $object->row, $selected, $sourceSnap, $targetSnap);
                if ($conflict !== null) {
                    $conflicts[] = $conflict;
                }
            }

            foreach ($object->descriptor->children as $child) {
                foreach ($this->childConflicts($token, $child, $object->row, $selected, $sourceSnap, $targetSnap) as $conflict) {
                    $conflicts[] = $conflict;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, array<string, true>>  $selected
     * @return list<PromotionConflict>
     */
    private function childConflicts(
        string $parentToken,
        ChildDescriptor $child,
        Model $sourceParent,
        array $selected,
        PlaneSnapshot $sourceSnap,
        PlaneSnapshot $targetSnap,
    ): array {
        $conflicts = [];

        foreach ($this->readChildren($child, $sourceParent) as $childRow) {
            foreach ($child->dependencies as $dependency) {
                $conflict = $this->dependencyConflict($parentToken, $dependency, $childRow, $selected, $sourceSnap, $targetSnap);
                if ($conflict !== null) {
                    $conflicts[] = $conflict;
                }
            }

            foreach ($child->children as $grandChild) {
                foreach ($this->childConflicts($parentToken, $grandChild, $childRow, $selected, $sourceSnap, $targetSnap) as $conflict) {
                    $conflicts[] = $conflict;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, array<string, true>>  $selected
     */
    private function dependencyConflict(
        string $token,
        DependencyDescriptor $dependency,
        Model $row,
        array $selected,
        PlaneSnapshot $sourceSnap,
        PlaneSnapshot $targetSnap,
    ): ?PromotionConflict {
        if (! $dependency->required) {
            return null;
        }

        $fkId = $row->getAttribute($dependency->attribute);
        if ($fkId === null || $fkId === '') {
            return null; // an absent optional reference is fine.
        }

        $dependencyKey = $sourceSnap->dependencyNaturalKey($dependency->type, $fkId);
        if ($dependencyKey === null) {
            return PromotionConflict::missingDependency($token, $dependency->type, '(unresolved)');
        }

        $presentInTarget = $targetSnap->find($dependency->type, $dependencyKey) !== null;
        $inSelection = isset($selected[$dependency->type][$dependencyKey]);

        if ($presentInTarget || $inSelection) {
            return null;
        }

        return PromotionConflict::missingDependency($token, $dependency->type, $dependencyKey);
    }

    // -----------------------------------------------------------------------------------------
    // Apply — upsert + relationship remapping (inside one transaction)
    // -----------------------------------------------------------------------------------------

    /**
     * @param  list<ResolvedObject>  $objects
     */
    private function applyObjects(
        array $objects,
        PlaneSnapshot $sourceSnap,
        PlaneSnapshot $targetSnap,
        Environment $target,
        PromotionPreview $preview,
    ): void {
        $targetIds = $this->seedTargetIds($targetSnap);
        $status = $this->statusIndex($preview);

        /** @var list<array{descriptor: ObjectDescriptor, targetRow: Model, sourceRow: Model}> $deferred */
        $deferred = [];

        foreach ($objects as $object) {
            $token = $object->descriptor->type.':'.$object->naturalKey;
            if (($status[$token] ?? ChangeStatus::Unchanged) === ChangeStatus::Unchanged) {
                continue; // idempotent — an unchanged object (and its children) is left untouched.
            }

            $targetRow = $this->upsertObject($object, $sourceSnap, $target, $targetIds);
            $targetIds[$object->descriptor->type][$object->naturalKey] = $this->keyOf($targetRow);

            $childMaps = $this->reconcileChildren($object->descriptor->children, $object->row, $targetRow, $sourceSnap, $targetSnap, $target, $targetIds);

            $this->resolveChildSelfReferences($object->descriptor, $object->row, $targetRow, $sourceSnap, $childMaps);

            if ($this->hasTypeSelfReference($object->descriptor)) {
                $deferred[] = ['descriptor' => $object->descriptor, 'targetRow' => $targetRow, 'sourceRow' => $object->row];
            }
        }

        $this->resolveTypeSelfReferences($deferred, $sourceSnap, $targetIds);
    }

    /**
     * Upsert one top-level object into the target plane: update a matched target row in place
     * (preserving its id), or create a fresh one with remapped dependency FKs. Self-references are
     * left for the deferred pass.
     *
     * @param  array<string, array<string, int|string>>  $targetIds
     */
    private function upsertObject(
        ResolvedObject $object,
        PlaneSnapshot $sourceSnap,
        Environment $target,
        array $targetIds,
    ): Model {
        $descriptor = $object->descriptor;
        $existingId = $targetIds[$descriptor->type][$object->naturalKey] ?? null;

        $targetRow = $existingId !== null
            ? $this->findWritable($descriptor->modelClass, $existingId)
            : $this->freshRow($descriptor, $object->row, $target);

        foreach ($descriptor->compareFields as $field) {
            $targetRow->setAttribute($field, $object->row->getAttribute($field));
        }

        $this->remapDependencies($descriptor->dependencies, $object->row, $targetRow, $sourceSnap, $targetIds);

        $targetRow->save();

        return $targetRow;
    }

    /**
     * Reconcile a parent's children into the target parent: upsert each source child (matched by
     * within-parent natural key), remapping its parent FK to the target parent and its cross-object
     * FKs to the target plane. Additive — a target child the source lacks is left in place.
     *
     * @param  list<ChildDescriptor>  $children
     * @param  array<string, array<string, int|string>>  $targetIds
     * @return array<string, array<string, int|string>> [relation][childNaturalKey] => target child id
     */
    private function reconcileChildren(
        array $children,
        Model $sourceParent,
        Model $targetParent,
        PlaneSnapshot $sourceSnap,
        PlaneSnapshot $targetSnap,
        Environment $target,
        array $targetIds,
    ): array {
        $maps = [];

        foreach ($children as $child) {
            // Existing target children are matched by their within-parent natural key, resolved
            // against the pre-apply target snapshot (their dependencies pre-date this promotion).
            $targetByKey = [];
            foreach ($this->readChildren($child, $targetParent) as $row) {
                $targetByKey[$targetSnap->childNaturalKey($child, $row)] = $row;
            }

            foreach ($this->readChildren($child, $sourceParent) as $sourceChild) {
                $key = $sourceSnap->childNaturalKey($child, $sourceChild);
                $targetChild = $targetByKey[$key] ?? $this->freshRow($child, $sourceChild, $target);

                foreach ($child->compareFields as $field) {
                    $targetChild->setAttribute($field, $sourceChild->getAttribute($field));
                }

                $targetChild->setAttribute($child->parentKey, $targetParent->getKey());
                $this->remapDependencies($child->dependencies, $sourceChild, $targetChild, $sourceSnap, $targetIds);
                $targetChild->save();

                $maps[$child->relation][$key] = $this->keyOf($targetChild);

                // Grandchildren (a price's tiers) are reconciled against the just-saved child.
                $this->reconcileChildren($child->children, $sourceChild, $targetChild, $sourceSnap, $targetSnap, $target, $targetIds);
            }
        }

        return $maps;
    }

    /**
     * Rewire a deferred type-scoped self-reference (a plan's default successor) to the TARGET
     * plane's id of the referenced object, resolved by natural key; left null when unresolved.
     *
     * @param  list<array{descriptor: ObjectDescriptor, targetRow: Model, sourceRow: Model}>  $deferred
     * @param  array<string, array<string, int|string>>  $targetIds
     */
    private function resolveTypeSelfReferences(array $deferred, PlaneSnapshot $sourceSnap, array $targetIds): void
    {
        foreach ($deferred as $entry) {
            $changed = false;

            foreach ($entry['descriptor']->selfReferences as $selfRef) {
                if ($selfRef->type === null) {
                    continue;
                }

                $pointerId = $entry['sourceRow']->getAttribute($selfRef->attribute);
                $targetId = null;

                if ($pointerId !== null && $pointerId !== '') {
                    $refKey = $sourceSnap->dependencyNaturalKey($selfRef->type, $pointerId);
                    $targetId = $refKey !== null ? ($targetIds[$selfRef->type][$refKey] ?? null) : null;
                }

                $entry['targetRow']->setAttribute($selfRef->attribute, $targetId);
                $changed = true;
            }

            if ($changed) {
                $entry['targetRow']->save();
            }
        }
    }

    /**
     * Rewire a child-scoped self-reference (an experiment's promoted variant) to the target child
     * id from the reconciliation map, resolved by the referenced child's within-parent natural key.
     *
     * @param  array<string, array<string, int|string>>  $childMaps
     */
    private function resolveChildSelfReferences(
        ObjectDescriptor $descriptor,
        Model $sourceRow,
        Model $targetRow,
        PlaneSnapshot $sourceSnap,
        array $childMaps,
    ): void {
        $changed = false;

        foreach ($descriptor->selfReferences as $selfRef) {
            if ($selfRef->childRelation === null) {
                continue;
            }

            $child = $this->childByRelation($descriptor, $selfRef->childRelation);
            $pointerId = $sourceRow->getAttribute($selfRef->attribute);
            $targetId = null;

            if ($child !== null && $pointerId !== null && $pointerId !== '' && is_scalar($pointerId)) {
                $sourceChild = $this->findChildById($child, $sourceRow, (string) $pointerId);
                if ($sourceChild !== null) {
                    $key = $sourceSnap->childNaturalKey($child, $sourceChild);
                    $targetId = $childMaps[$selfRef->childRelation][$key] ?? null;
                }
            }

            $targetRow->setAttribute($selfRef->attribute, $targetId);
            $changed = true;
        }

        if ($changed) {
            $targetRow->save();
        }
    }

    // -----------------------------------------------------------------------------------------
    // Apply helpers
    // -----------------------------------------------------------------------------------------

    /**
     * A fresh target row seeded from a source row: the natural-key plain attributes, the target
     * environment (and the livemode mirror where the table carries it), and — for the seller
     * register — a plane-namespaced minted id. Compare-fields and FKs are set by the caller.
     */
    private function freshRow(ObjectDescriptor|ChildDescriptor $descriptor, Model $sourceRow, Environment $target): Model
    {
        /** @var Model $row */
        $row = new $descriptor->modelClass;

        foreach ($descriptor->naturalKeyAttributes as $attribute) {
            if ($this->isDependencyAttribute($descriptor, $attribute)) {
                continue; // a natural-key attribute that is a FK is set by dependency remapping.
            }

            if ($attribute === 'id' && $descriptor instanceof ObjectDescriptor && $descriptor->mintsStringId) {
                $row->setAttribute('id', $this->mintStringId($sourceRow, $target));

                continue;
            }

            $row->setAttribute($attribute, $sourceRow->getAttribute($attribute));
        }

        $row->setAttribute('environment', $target->key);

        // Keep the legacy livemode mirror in step on the tables that still carry it (coupons).
        if (array_key_exists('livemode', $sourceRow->getAttributes())) {
            $row->setAttribute('livemode', $target->livemode());
        }

        // Server-owned redemption counter never carries across a promotion — a promoted coupon
        // starts fresh in the target plane (its redemption ledger lives only where it was earned).
        if ($row instanceof Coupon) {
            $row->setAttribute('times_redeemed', 0);
        }

        return $row;
    }

    /** The plane-namespaced string id for a created seller: bare in production, `{env}__key` in a sandbox. */
    private function mintStringId(Model $sourceRow, Environment $target): string
    {
        $id = $sourceRow->getKey();
        $id = is_scalar($id) ? (string) $id : '';
        $sourceEnv = $sourceRow->getAttribute('environment');
        $prefix = is_string($sourceEnv) ? $sourceEnv.'__' : '';
        $natural = $prefix !== '' && str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id;

        return $target->isProduction() ? $natural : $target->key.'__'.$natural;
    }

    /**
     * Remap a row's dependency FKs to the target plane's ids (by the referenced object's natural
     * key). A required dependency has already cleared conflict detection; a soft/unresolved one
     * is set null.
     *
     * @param  list<DependencyDescriptor>  $dependencies
     * @param  array<string, array<string, int|string>>  $targetIds
     */
    private function remapDependencies(
        array $dependencies,
        Model $sourceRow,
        Model $targetRow,
        PlaneSnapshot $sourceSnap,
        array $targetIds,
    ): void {
        foreach ($dependencies as $dependency) {
            $fkId = $sourceRow->getAttribute($dependency->attribute);

            if ($fkId === null || $fkId === '') {
                $targetRow->setAttribute($dependency->attribute, null);

                continue;
            }

            $refKey = $sourceSnap->dependencyNaturalKey($dependency->type, $fkId);
            $targetId = $refKey !== null ? ($targetIds[$dependency->type][$refKey] ?? null) : null;

            $targetRow->setAttribute($dependency->attribute, $targetId);
        }
    }

    /**
     * The target-plane id map seeded from every existing target object, so a dependency that
     * already lives in the target (and is not itself being promoted) still resolves.
     *
     * @return array<string, array<string, int|string>>
     */
    private function seedTargetIds(PlaneSnapshot $targetSnap): array
    {
        $ids = [];
        foreach ($this->surface->objects() as $descriptor) {
            foreach ($targetSnap->rowsOf($descriptor->type) as $naturalKey => $row) {
                $ids[$descriptor->type][$naturalKey] = $this->keyOf($row);
            }
        }

        return $ids;
    }

    /**
     * Index the preview's top-level object statuses by `type:key`, so the apply skips unchanged
     * objects without re-diffing.
     *
     * @return array<string, ChangeStatus>
     */
    private function statusIndex(PromotionPreview $preview): array
    {
        $index = [];
        foreach ($preview->changes as $change) {
            $index[$change->token()] = $change->status;
        }

        return $index;
    }

    // -----------------------------------------------------------------------------------------
    // Small typed helpers
    // -----------------------------------------------------------------------------------------

    /**
     * The children of a parent in its OWN plane — the global environment scope lifted and the
     * plane filtered explicitly on the base query (the same seam the snapshot and cloner use).
     *
     * @return EloquentCollection<int, Model>
     */
    private function readChildren(ChildDescriptor $child, Model $parent): EloquentCollection
    {
        $environment = $parent->getAttribute('environment');
        $query = $child->modelClass::query()->withoutGlobalScope(EnvironmentScope::class);
        $query->getQuery()
            ->where($child->parentKey, $parent->getKey())
            ->where('environment', is_string($environment) ? $environment : Environment::PRODUCTION);

        return $query->get();
    }

    /**
     * A writable target model by primary id, with the environment scope lifted (the id was already
     * resolved for the target plane, so no ambient-plane filter must interfere).
     *
     * @param  class-string<Model>  $modelClass
     */
    private function findWritable(string $modelClass, int|string $id): Model
    {
        $row = $modelClass::query()->withoutGlobalScope(EnvironmentScope::class)->findOrFail($id);

        return $row;
    }

    private function findChildById(ChildDescriptor $child, Model $parent, string $id): ?Model
    {
        foreach ($this->readChildren($child, $parent) as $row) {
            $key = $row->getKey();
            if (is_scalar($key) && (string) $key === $id) {
                return $row;
            }
        }

        return null;
    }

    private function childByRelation(ObjectDescriptor $descriptor, string $relation): ?ChildDescriptor
    {
        foreach ($descriptor->children as $child) {
            if ($child->relation === $relation) {
                return $child;
            }
        }

        return null;
    }

    private function hasTypeSelfReference(ObjectDescriptor $descriptor): bool
    {
        foreach ($descriptor->selfReferences as $selfRef) {
            if ($selfRef->type !== null) {
                return true;
            }
        }

        return false;
    }

    private function isDependencyAttribute(ObjectDescriptor|ChildDescriptor $descriptor, string $attribute): bool
    {
        foreach ($descriptor->dependencies as $dependency) {
            if ($dependency->attribute === $attribute) {
                return true;
            }
        }

        return false;
    }

    private function keyOf(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) ? $key : (string) (is_scalar($key) ? $key : '');
    }

    private function labelFor(Model $row, string $fallback): string
    {
        $name = $row->getAttribute('name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $fallback;
    }

    private function display(string $normalized): string
    {
        return $normalized === '∅' ? '—' : $normalized;
    }

    // -----------------------------------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------------------------------

    private function recordAudit(Environment $source, Environment $target, PromotionPreview $preview): void
    {
        $objects = array_map(
            static fn (ObjectChange $change): array => $change->toAuditArray(),
            array_values(array_filter($preview->changes, static fn (ObjectChange $c): bool => $c->writes())),
        );

        $this->audit->record(
            AuditAction::ConfigPromoted,
            AuditTarget::of('environment', $target->key),
            sprintf(
                'Promoted config from “%s” to “%s” (%d created, %d updated).',
                $source->key,
                $target->key,
                $preview->createdCount(),
                $preview->updatedCount(),
            ),
            [
                'source' => $source->key,
                'target' => $target->key,
                'created' => $preview->createdCount(),
                'updated' => $preview->updatedCount(),
                'unchanged' => $preview->unchangedCount(),
                'objects' => $objects,
            ],
            livemode: $target->livemode(),
        );
    }
}
