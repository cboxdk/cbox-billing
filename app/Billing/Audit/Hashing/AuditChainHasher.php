<?php

declare(strict_types=1);

namespace App\Billing\Audit\Hashing;

use App\Billing\Audit\AuditChainVerifier;
use App\Models\OperatorAuditEvent;

/**
 * Computes the per-row chain hash. The hash binds a row to its predecessor:
 *
 *     hash = SHA-256( prev_hash · "\n" · canonical(payload) )
 *
 * where `payload` is the identity-defining subset of the row (everything an operator could
 * tamper with) and `prev_hash` is the previous row's `hash`. Because each row's hash feeds the
 * next row's input, editing any single row breaks that row AND every row after it — which is
 * exactly what {@see AuditChainVerifier} detects.
 *
 * This is tamper-EVIDENT, not tamper-PROOF: the hash is unkeyed, so an actor who can rewrite
 * the table can also recompute a fresh, self-consistent chain. It reliably catches partial or
 * careless edits, DB-level fiddling, and accidental corruption — not a full, deliberate rewrite.
 */
class AuditChainHasher
{
    /** The genesis predecessor hash — 64 zero hex chars — for the very first row. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Hash a row's payload against a predecessor hash.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(string $prevHash, array $payload): string
    {
        return hash('sha256', $prevHash."\n".CanonicalPayload::encode($payload));
    }

    /**
     * The canonical, hashable payload of a persisted event — the fields whose integrity the
     * chain protects. `id` and `created_at` are deliberately excluded (surrogate/bookkeeping);
     * `sequence` and `prev_hash` ARE included so re-linking or reordering is detectable.
     *
     * @return array<string, mixed>
     */
    public function payloadOf(OperatorAuditEvent $event): array
    {
        return [
            'sequence' => (int) $event->sequence,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'actor_sub' => $event->actor_sub,
            'actor_name' => $event->actor_name,
            'actor_ip' => $event->actor_ip,
            'action' => $event->action,
            'target_type' => $event->target_type,
            'target_id' => $event->target_id,
            'organization_id' => $event->organization_id,
            'summary' => $event->summary,
            'metadata' => $event->metadata ?? [],
            'livemode' => (bool) $event->livemode,
        ];
    }
}
