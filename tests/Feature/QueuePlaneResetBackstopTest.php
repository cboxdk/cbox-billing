<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Tests\TestCase;

/**
 * A queued job that records the ambient plane it observed when it ran.
 */
class RecordsAmbientPlaneJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $seen = null;

    public function handle(BillingContext $context): void
    {
        self::$seen = $context->environmentKey();
    }
}

/**
 * Defense-in-depth: the queue worker resets the BillingContext to the production default before
 * each job, so a plane a prior job left on the long-lived singleton never leaks into the next.
 */
class QueuePlaneResetBackstopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordsAmbientPlaneJob::$seen = null;
        config(['queue.default' => 'sync']);
    }

    public function test_a_job_starts_in_production_even_if_a_prior_plane_lingers(): void
    {
        // Simulate a leftover plane set on the singleton by an earlier request/job.
        app(BillingContext::class)->setEnvironment(Environment::defaultSandbox());
        $this->assertSame('sandbox', app(BillingContext::class)->environmentKey());

        // Running a job resets the plane first — the job observes production, not the leftover sandbox.
        RecordsAmbientPlaneJob::dispatch();

        $this->assertSame('production', RecordsAmbientPlaneJob::$seen);
    }
}
