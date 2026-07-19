<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Metering\CumulativeUsageIngest;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\MeterIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cumulative-reading ingest converts a monotonic per-meter cumulative into the delta the
 * immutable event log stores. The regression here is M1: within a single batch the per-meter
 * baseline must advance as deltas are accepted, so two readings for the SAME meter measure
 * from the running total rather than the stale pre-batch durable sum.
 */
class CumulativeUsageIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_m1_two_readings_for_one_meter_in_a_batch_do_not_double_count(): void
    {
        $now = 1_700_000_000_000;
        $ingest = new CumulativeUsageIngest(app(EventLog::class), app(MeterIngest::class), 'api', static fn (): int => $now);

        // Establish a pre-batch durable baseline of 100.
        $ingest->ingest('org_m1', [['meter' => 'api.requests', 'cumulative' => 100, 'seq' => 1]]);
        $this->assertSame(100, app(EventLog::class)->sum('org_m1', 'api.requests', 0, $now));

        // A batch with two readings for the SAME meter: 150 then 180. The durable sum must land
        // on 180 (deltas 50 + 30), never 230 (both measuring the stale pre-batch 100). The
        // events are appended only after the loop, so without advancing the baseline in-batch
        // the second delta would be 180 − 100 = 80.
        $appended = $ingest->ingest('org_m1', [
            ['meter' => 'api.requests', 'cumulative' => 150, 'seq' => 2],
            ['meter' => 'api.requests', 'cumulative' => 180, 'seq' => 3],
        ]);

        $this->assertSame(2, $appended);
        $this->assertSame(180, app(EventLog::class)->sum('org_m1', 'api.requests', 0, $now));
    }

    public function test_a_second_meter_in_the_same_batch_keeps_its_own_baseline(): void
    {
        $now = 1_700_000_000_000;
        $ingest = new CumulativeUsageIngest(app(EventLog::class), app(MeterIngest::class), 'api', static fn (): int => $now);

        // Two distinct meters in one batch are tracked independently.
        $ingest->ingest('org_m1b', [
            ['meter' => 'api.requests', 'cumulative' => 40, 'seq' => 1],
            ['meter' => 'events.ingested', 'cumulative' => 90, 'seq' => 2],
            ['meter' => 'api.requests', 'cumulative' => 55, 'seq' => 3],
        ]);

        $this->assertSame(55, app(EventLog::class)->sum('org_m1b', 'api.requests', 0, $now));
        $this->assertSame(90, app(EventLog::class)->sum('org_m1b', 'events.ingested', 0, $now));
    }
}
