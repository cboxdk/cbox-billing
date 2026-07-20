<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Durable first-sight dedup for gateway webhook events, keyed on the gateway's own event
 * id. `remember()` is a UNIQUE insert: it returns true the first time an id is seen and
 * false on every re-delivery, so the event stream is deduplicated across process restarts
 * — not just within one process like the engine's in-memory default.
 *
 * Plane-aware: the dedup row carries the request's `environment` (with `livemode` as its mirror)
 * so a sandbox event stream and a production one are deduplicated independently and never suppress
 * each other.
 */
readonly class DatabaseProcessedEventStore implements ProcessedEventStore
{
    private const TABLE = 'webhook_processed_events';

    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function remember(string $eventId): bool
    {
        try {
            $this->db->table(self::TABLE)->insert([
                'event_id' => $eventId,
                'environment' => $this->context->environmentKey(),
                'livemode' => $this->context->livemode(),
            ]);

            return true;
        } catch (QueryException) {
            // Unique-violation on re-delivery: already processed.
            return false;
        }
    }
}
