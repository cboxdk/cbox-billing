<?php

declare(strict_types=1);

namespace App\Billing\Metering;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\MeterIngest;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Closure;

/**
 * Cumulative usage ingest — the `/usage` hot path. The SDK reports a monotonically
 * increasing CUMULATIVE reading per meter (`{meter, cumulative, seq}`); this converts it
 * into the delta the immutable {@see EventLog} stores, then appends via the idempotent
 * {@see MeterIngest}.
 *
 * It is self-correcting by construction: the delta is `cumulative − currentDurableSum`,
 * so a re-delivered or older reading yields a non-positive delta and is ignored, a gap
 * (a missed report) is healed by the next reading (the delta spans it), and the durable
 * sum always converges to the latest cumulative — no ordering or exactly-once delivery is
 * required from the network. `seq` only stabilises the dedup key.
 */
readonly class CumulativeUsageIngest
{
    /** @param  (Closure(): int)|null  $clock  millisecond-epoch clock (deterministic in tests) */
    public function __construct(
        private EventLog $eventLog,
        private MeterIngest $ingest,
        private string $service = 'api',
        private ?Closure $clock = null,
    ) {}

    /**
     * Ingest a batch of cumulative readings for one org. Returns the number of usage
     * events newly appended (a reading that adds nothing appends nothing).
     *
     * @param  list<array{meter: string, cumulative: int, seq: int}>  $entries
     */
    public function ingest(string $org, array $entries): int
    {
        $now = $this->now();
        $events = [];

        // The per-meter baseline advances WITHIN the batch (M1): the durable sum is read once
        // per meter, then each accepted delta is folded back in so a second reading for the
        // same meter measures from the running total, not the stale pre-batch baseline. Two
        // entries [(m,150),(m,180)] over a durable 100 thus append 50 then 30 (→180), never
        // 50 then 80 (→230). Appending only happens after the loop, so without this the
        // eventLog sum would report the same pre-batch figure for both.
        $baselines = [];

        foreach ($entries as $entry) {
            $meter = $entry['meter'];
            $cumulative = $entry['cumulative'];
            $seq = $entry['seq'];

            if (! array_key_exists($meter, $baselines)) {
                $baselines[$meter] = $this->eventLog->sum($org, $meter, 0, $now);
            }

            $delta = $cumulative - $baselines[$meter];

            if ($delta <= 0) {
                continue;
            }

            // Advance the running baseline by the accepted delta so a later, higher reading for
            // the same meter in this batch is measured from here.
            $baselines[$meter] += $delta;

            $events[] = new UsageEvent(
                id: sprintf('%s:%s:%d', $org, $meter, $seq),
                org: $org,
                meter: $meter,
                service: $this->service,
                value: $delta,
                occurredAt: $now,
            );
        }

        return $events === [] ? 0 : $this->ingest->ingest($events);
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return (int) round(microtime(true) * 1000);
    }
}
