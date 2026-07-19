<?php

declare(strict_types=1);

namespace App\Billing\Audit;

use App\Billing\Audit\Hashing\AuditChainHasher;
use App\Billing\Audit\ValueObjects\ChainStatus;
use App\Models\OperatorAuditEvent;

/**
 * Walks the audit hash chain in sequence order and reports the first integrity break. For each
 * row it re-derives the expected hash from the previous row's hash and the row's own canonical
 * payload, and checks three invariants:
 *
 *  - `sequence` increments by exactly 1 (no gap / no reorder);
 *  - `prev_hash` equals the previous row's stored `hash` (the link is intact);
 *  - the stored `hash` equals the recomputed hash (the row's contents are unmodified).
 *
 * The first row that violates any invariant is reported as the break point. The walk streams
 * with a chunked cursor, so verifying is memory-bounded regardless of trail size.
 */
readonly class AuditChainVerifier
{
    public function __construct(private AuditChainHasher $hasher) {}

    public function verify(): ChainStatus
    {
        $verified = 0;
        $prevHash = AuditChainHasher::GENESIS_HASH;
        $expectedSequence = 1;

        foreach (OperatorAuditEvent::query()->orderBy('sequence')->cursor() as $event) {
            if ($event->sequence !== $expectedSequence) {
                return ChainStatus::broken($verified, $event->sequence, sprintf(
                    'sequence gap — expected %d, found %d', $expectedSequence, $event->sequence,
                ));
            }

            if ($event->prev_hash !== $prevHash) {
                return ChainStatus::broken($verified, $event->sequence, 'prev_hash does not link to the previous row');
            }

            $recomputed = $this->hasher->hash($prevHash, $this->hasher->payloadOf($event));

            if (! hash_equals($recomputed, (string) $event->hash)) {
                return ChainStatus::broken($verified, $event->sequence, 'stored hash does not match recomputed hash (row modified)');
            }

            $verified++;
            $prevHash = $event->hash;
            $expectedSequence++;
        }

        return ChainStatus::ok($verified);
    }
}
