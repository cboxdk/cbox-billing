<?php

declare(strict_types=1);

namespace App\Billing\Export\Enums;

/**
 * How a dataset's staged files are meant to be loaded into a warehouse, and therefore how
 * an incremental sync partitions and how the load-manifest generators phrase their statements.
 *
 *  - {@see Append}: an immutable, append-only stream (the raw usage-event log). Each sync
 *    stages only rows past the watermark into a new date partition and the load is a plain
 *    COPY/`bq load` that adds rows — existing partitions are never rewritten.
 *  - {@see Upsert}: a mutable dimensional table (invoices, subscriptions, customers …). Rows
 *    change, so each sync stages everything touched since the watermark and the load MERGEs on
 *    the natural key, replacing a changed row rather than duplicating it.
 *  - {@see Snapshot}: a fully-recomputed, point-in-time view (the per-subscription revenue
 *    snapshot). There is no meaningful watermark — each sync stages the whole current set and
 *    the load truncates-and-replaces the target.
 */
enum SyncMode: string
{
    case Append = 'append';
    case Upsert = 'upsert';
    case Snapshot = 'snapshot';

    /** Whether a per-(dataset, sink) watermark is advanced for this mode (Snapshot carries none). */
    public function usesWatermark(): bool
    {
        return $this !== self::Snapshot;
    }
}
