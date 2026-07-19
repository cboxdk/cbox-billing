<?php

declare(strict_types=1);

namespace App\Billing\Audit;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Contracts\ResolvesAuditActor;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\Hashing\AuditChainHasher;
use App\Billing\Audit\Support\AuditRequestTally;
use App\Billing\Audit\ValueObjects\AuditActor;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Mode\BillingContext;
use App\Models\OperatorAuditEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The concrete append-only recorder. Each {@see record()} call:
 *
 *  1. resolves the actor (the signed-in operator, or the `system` sentinel) and the plane;
 *  2. inside a transaction, reads the chain tip under a row lock (`lockForUpdate`), assigns
 *     `sequence = tip + 1` and `prev_hash = tip.hash` (or the genesis hash for the first row),
 *     computes this row's `hash`, and inserts the immutable event;
 *  3. bumps the per-request tally so the recording middleware knows an event was logged.
 *
 * Concurrency is handled without a cache/redis lock (so it works on every deployment regardless
 * of cache driver): the tip row-lock serializes appenders, and the UNIQUE constraint on
 * `sequence` is the backstop for the one unlockable case — the genesis insert on an empty table —
 * where a lost race retries against the now-non-empty chain. The DB triggers keep every persisted
 * row immutable thereafter.
 */
readonly class AuditRecorder implements RecordsAudit
{
    /** Retries for the (rare) sequence collision at genesis before giving up. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private ConnectionInterface $db,
        private AuditChainHasher $hasher,
        private ResolvesAuditActor $actors,
        private BillingContext $context,
        private AuditRequestTally $tally,
    ) {}

    public function record(
        AuditAction $action,
        AuditTarget $target,
        string $summary,
        array $metadata = [],
        ?AuditActor $actor = null,
        ?bool $livemode = null,
    ): OperatorAuditEvent {
        $actor ??= $this->actors->resolve();
        $livemode ??= $this->context->livemode();

        $event = $this->appendWithRetry($action, $target, $summary, $metadata, $actor, $livemode);

        $this->tally->increment();

        return $event;
    }

    /**
     * Append, retrying only on a sequence-uniqueness collision (a lost genesis race).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function appendWithRetry(
        AuditAction $action,
        AuditTarget $target,
        string $summary,
        array $metadata,
        AuditActor $actor,
        bool $livemode,
    ): OperatorAuditEvent {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->append($action, $target, $summary, $metadata, $actor, $livemode);
            } catch (QueryException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || ! $this->isSequenceCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Unable to append the audit event after repeated sequence collisions.');
    }

    /**
     * The locked, transactional append: read the tip, link the chain, insert.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function append(
        AuditAction $action,
        AuditTarget $target,
        string $summary,
        array $metadata,
        AuditActor $actor,
        bool $livemode,
    ): OperatorAuditEvent {
        return $this->db->transaction(function () use ($action, $target, $summary, $metadata, $actor, $livemode): OperatorAuditEvent {
            $tip = OperatorAuditEvent::query()->lockForUpdate()->orderByDesc('sequence')->first();

            $sequence = $tip !== null ? $tip->sequence + 1 : 1;
            $prevHash = $tip !== null ? $tip->hash : AuditChainHasher::GENESIS_HASH;
            $now = Carbon::now();

            $event = new OperatorAuditEvent;
            $event->forceFill([
                'sequence' => $sequence,
                'occurred_at' => $now,
                'actor_sub' => $actor->sub,
                'actor_name' => $actor->name,
                'actor_ip' => $actor->ip,
                'action' => $action->value,
                'target_type' => $target->type,
                'target_id' => $target->id,
                'organization_id' => $target->organizationId,
                'summary' => $summary,
                'metadata' => $metadata === [] ? null : $metadata,
                'livemode' => $livemode,
                'prev_hash' => $prevHash,
                'created_at' => $now,
            ]);

            $event->hash = $this->hasher->hash($prevHash, $this->hasher->payloadOf($event));
            $event->save();

            return $event;
        });
    }

    /** Whether a query exception is the unique-sequence collision we retry on. */
    private function isSequenceCollision(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'sequence') && (
            str_contains($message, 'unique') || str_contains($message, 'duplicate') || str_contains($message, 'constraint')
        );
    }
}
