<?php

declare(strict_types=1);

namespace App\Billing\Export\Contracts;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportCursor;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\ExportRow;

/**
 * One exportable dataset — a stable, typed projection of a billing table (or a computed view)
 * that can be streamed to CSV or NDJSON, scoped to a plane and an optional date range, and
 * synced incrementally to a warehouse. A dataset owns its schema, its plane partition, its
 * business date column, and its incremental cursor; the encoders, sinks and manifests are all
 * generic over this contract, so adding a dataset is a single registered class.
 *
 * Rows MUST be yielded lazily (a chunked cursor), never materialised as a whole — every
 * consumer streams, so an export is memory-bounded regardless of table size.
 */
interface ExportDataset
{
    /** The stable slug (snake_case) this dataset is addressed by in URLs, config and paths. */
    public function key(): string;

    /** A short human label for the console picker. */
    public function label(): string;

    /** One sentence describing what a row represents. */
    public function description(): string;

    /**
     * The ordered, typed column schema — the single source the CSV header, the NDJSON keys and
     * the warehouse DDL all derive from.
     *
     * @return list<ExportColumn>
     */
    public function schema(): array;

    /** How this dataset is meant to be loaded (append / upsert / snapshot). */
    public function syncMode(): SyncMode;

    /**
     * The natural-key columns a warehouse MERGE dedupes on for an upsert load. Ignored for
     * append/snapshot datasets. Every column returned here is part of {@see schema()}.
     *
     * @return list<string>
     */
    public function mergeKeys(): array;

    /** The incremental delivery cursor (the monotonic column a sync orders and advances by). */
    public function cursor(): ExportCursor;

    /**
     * The business date column a user-facing range filters on (e.g. `issued_at`), or null when
     * the dataset has no natural date axis (line items, computed snapshots).
     */
    public function dateColumn(): ?string;

    /**
     * Stream the scoped rows lazily. The query carries the plane, the optional inclusive date
     * range, and the optional incremental watermark; the returned iterable yields one
     * {@see ExportRow} per record, in ascending cursor order.
     *
     * @return iterable<int, ExportRow>
     */
    public function rows(ExportQuery $query): iterable;
}
