<?php

declare(strict_types=1);

namespace App\Billing\Import;

use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportSource;
use App\Models\ImportSourceRef;

/**
 * The plane-scoped accessor for the idempotency ledger. Every lookup + write goes through here so
 * the natural key (`source`, `source_type`, `source_id`) and the plane partition are applied in
 * exactly one place. A found ref means "already imported" — the importer skips (or updates) rather
 * than duplicating.
 */
readonly class ImportSourceRefStore
{
    /** The ref for a provider record in the current plane, or null when it has not been imported. */
    public function find(ImportSource $source, ImportEntityType $type, string $sourceId): ?ImportSourceRef
    {
        return ImportSourceRef::query()
            ->where('source', $source->value)
            ->where('source_type', $type->value)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Every ledger ref for a source in the current plane, keyed by "type|sourceId" — the whole
     * idempotency set in ONE query, so an import walk resolves each row's "already imported?"
     * check from memory instead of a lookup per record.
     *
     * @return array<string, ImportSourceRef>
     */
    public function snapshot(ImportSource $source): array
    {
        return ImportSourceRef::query()
            ->where('source', $source->value)
            ->get()
            ->keyBy(static fn (ImportSourceRef $ref): string => $ref->source_type.'|'.$ref->source_id)
            ->all();
    }

    /**
     * Record (or re-affirm) the mapping from a provider record to an app record — idempotent on
     * the natural key, so a re-run overwrites the same row rather than inserting a duplicate.
     */
    public function record(
        ImportSource $source,
        ImportEntityType $type,
        string $sourceId,
        string $appType,
        string $appId,
        ?int $importRunId,
    ): ImportSourceRef {
        $ref = ImportSourceRef::query()->updateOrCreate(
            [
                'source' => $source->value,
                'source_type' => $type->value,
                'source_id' => $sourceId,
            ],
            [
                'app_type' => $appType,
                'app_id' => $appId,
                'import_run_id' => $importRunId,
            ],
        );

        return $ref;
    }
}
