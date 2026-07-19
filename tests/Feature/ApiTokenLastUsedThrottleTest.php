<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-5: `last_used_at` is a coarse "recently seen" signal, so the authenticator throttles its
 * write on the hot path — it re-stamps only when the previous stamp is older than the window,
 * not on every authenticated call (which would be a write + row lock for no new information).
 */
class ApiTokenLastUsedThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_used_at_is_stamped_once_per_window_not_every_call(): void
    {
        config(['billing.api.last_used_throttle_seconds' => 300]);
        Organization::query()->create(['id' => 'org_hot', 'name' => 'Hot', 'billing_country' => 'DK']);
        ['plaintext' => $plaintext] = ApiToken::issue('hot-path', 'org_hot');
        $auth = app(ApiTokenAuthenticator::class);

        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));
        $this->assertNotNull($auth->authenticate($plaintext));
        $first = ApiToken::query()->where('name', 'hot-path')->firstOrFail()->last_used_at;
        $this->assertNotNull($first);

        // A second authentication 10s later, inside the window: no UPDATE is issued.
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:10'));
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'update')) {
                $writes[] = $query->sql;
            }
        });
        $this->assertNotNull($auth->authenticate($plaintext));
        $this->assertSame([], $writes, 'A repeat authentication inside the window must not rewrite last_used_at.');
        $this->assertEquals($first, ApiToken::query()->where('name', 'hot-path')->firstOrFail()->last_used_at);

        // Past the window, the stamp advances.
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:10:00'));
        $this->assertNotNull($auth->authenticate($plaintext));
        $this->assertTrue(ApiToken::query()->where('name', 'hot-path')->firstOrFail()->last_used_at?->gt($first));

        Carbon::setTestNow();
    }
}
