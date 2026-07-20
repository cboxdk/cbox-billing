<?php

declare(strict_types=1);

namespace App\Billing\Payments\Exceptions;

use RuntimeException;

/**
 * A verified settlement webhook was REFUSED by the host effect because its amount/currency did
 * not match what was owed (a wrong-amount or wrong-currency settlement against an invoice, or a
 * checkout intent's stamped expectation). The rejection is already flagged in the audit log; the
 * applier throws this so the exactly-once ingest can abort BEFORE committing the settle-once /
 * processed-event guards.
 *
 * This is the fix for the dedup-consumption bug: a rejected settlement must NOT write the durable
 * guards, or a later CORRECT settlement for the same reference would collapse to a no-op "already
 * settled" duplicate and never apply. Because the ingest catches this and returns normally, the
 * audit record (written before the throw) still commits with the surrounding transaction, while
 * the guards are left unwritten so the corrected retry applies cleanly.
 */
class SettlementRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forReference(string $reference, string $message): self
    {
        return new self($reference, $message);
    }
}
