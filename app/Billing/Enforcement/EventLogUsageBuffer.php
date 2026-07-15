<?php

declare(strict_types=1);

namespace App\Billing\Enforcement;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * The {@see UsageBuffer} the server-side enforcement commits into. Because this app IS
 * billing (not an edge node syncing back to it), a committed usage event is written
 * straight through to the durable {@see EventLog} — the metering source of truth
 * reconciliation and invoicing read from — rather than held in a local WAL for later
 * shipping. Appends are idempotent on the event id, so a retried commit never
 * double-counts.
 */
readonly class EventLogUsageBuffer implements UsageBuffer
{
    public function __construct(private EventLog $eventLog) {}

    public function append(UsageEvent $event): void
    {
        $this->eventLog->append([$event]);
    }

    /**
     * Nothing is buffered locally — events are already durable in the event log — so
     * there is never anything to drain.
     *
     * @return list<UsageEvent>
     */
    public function drain(int $limit = 1000): array
    {
        return [];
    }

    public function size(): int
    {
        return 0;
    }
}
