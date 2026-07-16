<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The observability health surface (cboxdk/laravel-health). Liveness and readiness are the
 * public probes an orchestrator hits; readiness exercises the real DB, cache and queue
 * connectivity checks. The detail endpoints are token-gated.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_is_public_and_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_readiness_reports_database_cache_and_queue_checks(): void
    {
        $response = $this->getJson('/health/ready')->assertOk();

        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database.status', 'ok');
        $response->assertJsonPath('checks.cache.status', 'ok');
        $response->assertJsonPath('checks.queue.status', 'ok');
    }

    public function test_the_native_up_route_still_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_detail_endpoint_is_gated_outside_local(): void
    {
        // In the testing environment the default health auth callback denies, so the
        // detail status endpoint (which surfaces more than up/down) is not public.
        $this->getJson('/health/status')->assertStatus(403);
    }
}
