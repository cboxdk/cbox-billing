<?php

declare(strict_types=1);

namespace App\Billing\Import\Enums;

/**
 * What the importer decided for one source record — the same verbs the dry-run PLAN reports and
 * the commit LOG records, so a planned action reads identically to its executed outcome.
 *
 *  - Created: no prior mapping / natural-key match — a new app record is (or would be) created.
 *  - Updated: an existing mapping / natural-key match — the app record is (or would be) updated.
 *  - Skipped: an existing mapping whose app record is unchanged — a no-op (the idempotent re-run).
 *  - Conflict: a resolvable-only-by-an-operator problem (an unmapped plan, a duplicate email, an
 *    unsupported currency/interval) — surfaced in the dry-run, never silently guessed or written.
 *  - Failed: the write itself was refused by a domain service at commit time.
 */
enum ImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Conflict = 'conflict';
    case Failed = 'failed';

    /** Whether this outcome represents (or would represent) a successful write. */
    public function isWrite(): bool
    {
        return $this === self::Created || $this === self::Updated;
    }

    /** Whether this outcome blocks a commit (needs operator resolution first). */
    public function isBlocking(): bool
    {
        return $this === self::Conflict;
    }
}
