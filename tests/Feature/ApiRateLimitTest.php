<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Providers\AppServiceProvider;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-token API rate limiting. The enforcement hot path and the management surface are
 * throttled on their own config-driven tiers, keyed per bearer token, and the webhook has its
 * own ceiling. Past the limit the API answers 429. Limits are lowered here and the named
 * limiters re-registered from config (the same path the provider runs at boot).
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        Organization::query()->create(['id' => 'org_rl', 'name' => 'RL Co', 'billing_country' => 'DK']);
    }

    public function test_management_tier_returns_429_past_the_limit(): void
    {
        $this->setLimits(management: 2);
        $auth = $this->tokenFor('org_rl');

        $this->getJson('/api/v1/plans', $auth)->assertOk();
        $this->getJson('/api/v1/plans', $auth)->assertOk();
        $this->getJson('/api/v1/plans', $auth)->assertStatus(429);
    }

    public function test_the_limit_is_per_token_not_global(): void
    {
        $this->setLimits(management: 2);
        $tokenA = $this->tokenFor('org_rl');

        Organization::query()->create(['id' => 'org_rl2', 'name' => 'RL Two', 'billing_country' => 'DK']);
        $tokenB = $this->tokenFor('org_rl2');

        // Token A exhausts its own budget…
        $this->getJson('/api/v1/plans', $tokenA)->assertOk();
        $this->getJson('/api/v1/plans', $tokenA)->assertOk();
        $this->getJson('/api/v1/plans', $tokenA)->assertStatus(429);

        // …while token B still has its full budget.
        $this->getJson('/api/v1/plans', $tokenB)->assertOk();
    }

    public function test_enforcement_tier_returns_429_past_the_limit(): void
    {
        $this->setLimits(enforcement: 2);
        $auth = $this->tokenFor('org_rl');

        $this->getJson('/api/v1/entitlements/org_rl', $auth);
        $this->getJson('/api/v1/entitlements/org_rl', $auth);
        $this->getJson('/api/v1/entitlements/org_rl', $auth)->assertStatus(429);
    }

    public function test_webhook_route_is_throttled(): void
    {
        $this->setLimits(webhook: 2);

        // The signature is invalid (400), but the throttle runs first — the 3rd call is 429.
        $this->postJson('/webhooks/manual', ['x' => 1]);
        $this->postJson('/webhooks/manual', ['x' => 1]);
        $this->postJson('/webhooks/manual', ['x' => 1])->assertStatus(429);
    }

    /** Lower the tiers in config and re-register the named limiters from it. */
    private function setLimits(?int $enforcement = null, ?int $management = null, ?int $webhook = null): void
    {
        config([
            'billing.rate_limits.enforcement' => $enforcement ?? 600,
            'billing.rate_limits.management' => $management ?? 60,
            'billing.rate_limits.webhook' => $webhook ?? 120,
        ]);

        (new AppServiceProvider($this->app))->boot();
    }

    /** @return array<string, string> */
    private function tokenFor(string $org): array
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        return ['Authorization' => 'Bearer '.$token];
    }
}
