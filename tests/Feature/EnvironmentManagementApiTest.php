<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Models\ApiToken;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The token-authed environment-management API (`/api/v1/environments`) — the CI + programmatic
 * surface. Covers the whole throwaway-environment lifecycle end to end: create-cloned → env-bound
 * token → isolated writes → destroy → gone; that an env-bound token cannot act across environments;
 * and that production is never reset or destroyed.
 */
class EnvironmentManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production owns the seeded catalog; the sandbox plane exists but is empty.
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    /** @return array<string, string> An operator (production-bound) token's auth header. */
    private function operatorAuth(): array
    {
        ['plaintext' => $token] = ApiToken::issue('ci-operator');

        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    public function test_ci_flow_create_cloned_env_scoped_token_isolated_writes_then_destroy(): void
    {
        // 1) CI provisions a throwaway sandbox by cloning production's config; it gets a token back.
        $create = $this->postJson('/api/v1/environments', [
            'key' => 'ci-run',
            'name' => 'CI Run',
            'clone_from' => 'production',
        ], $this->operatorAuth());

        $create->assertCreated()
            ->assertJsonPath('environment.key', 'ci-run')
            ->assertJsonPath('environment.type', 'sandbox')
            ->assertJsonPath('environment.gateway_key_mode', 'test')
            ->assertJsonPath('cloned', true);

        $ciToken = $create->json('token');
        $this->assertIsString($ciToken);
        $this->assertNotEmpty($ciToken);

        // The clone copied production's catalog into the new plane (isolated rows).
        $this->inEnvironment('ci-run', function (): void {
            $this->assertGreaterThan(0, Plan::query()->where('key', 'starter')->count());
        });

        // 2) The CI token drives normal management calls — all resolving the ci-run plane.
        $this->putJson('/api/v1/organizations/org_ci', [
            'name' => 'CI Tenant',
            'billing_country' => 'DK',
        ], $this->bearer($ciToken))->assertSuccessful();

        $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_ci',
            'plan' => 'starter',
            'seats' => 1,
        ], $this->bearer($ciToken))->assertCreated();

        // 3) Isolation: the writes landed ONLY in ci-run, never in production.
        $this->inEnvironment('ci-run', function (): void {
            $this->assertSame(1, Organization::query()->count());
            $this->assertSame(1, Subscription::query()->count());
        });
        $this->inEnvironment('production', function (): void {
            $this->assertSame(0, Organization::query()->count(), 'the CI env writes never touched production');
            $this->assertSame(0, Subscription::query()->count());
        });

        // 4) Teardown: the CI token destroys its own plane.
        $this->deleteJson('/api/v1/environments/ci-run', [], $this->bearer($ciToken))
            ->assertOk()
            ->assertJsonPath('destroyed', true);

        // 5) Gone: the environment row, all its data, and the token itself are removed.
        $this->assertNull(Environment::query()->where('key', 'ci-run')->first());
        $this->inEnvironment('production', function (): void {
            $this->assertSame(0, Subscription::query()->count());
        });
        $this->assertSame(0, Subscription::query()->withoutGlobalScopes()->where('environment', 'ci-run')->count());
        $this->assertSame(0, Organization::query()->withoutGlobalScopes()->where('environment', 'ci-run')->count());
        $this->assertSame(0, Plan::query()->withoutGlobalScopes()->where('environment', 'ci-run')->count());

        // The env-bound token no longer authenticates (it died with the plane).
        $this->getJson('/api/v1/environments', $this->bearer($ciToken))->assertUnauthorized();
    }

    public function test_env_bound_token_cannot_manage_another_environment(): void
    {
        // Two sandboxes, each with its own env-bound token.
        $a = $this->postJson('/api/v1/environments', ['key' => 'sbx-a'], $this->operatorAuth());
        $b = $this->postJson('/api/v1/environments', ['key' => 'sbx-b'], $this->operatorAuth());
        $a->assertCreated();
        $b->assertCreated();
        $tokenA = $a->json('token');

        // A's token may manage A …
        $this->getJson('/api/v1/environments/sbx-a', $this->bearer($tokenA))->assertOk();

        // … but not B (cross-environment isolation) — and not production.
        $this->getJson('/api/v1/environments/sbx-b', $this->bearer($tokenA))->assertForbidden();
        $this->postJson('/api/v1/environments/sbx-b/reset', [], $this->bearer($tokenA))->assertForbidden();
        $this->deleteJson('/api/v1/environments/sbx-b', [], $this->bearer($tokenA))->assertForbidden();
        $this->deleteJson('/api/v1/environments/production', [], $this->bearer($tokenA))->assertForbidden();

        // B still exists — the refused calls changed nothing.
        $this->assertNotNull(Environment::query()->where('key', 'sbx-b')->first());
    }

    public function test_production_cannot_be_destroyed_or_reset_via_the_api(): void
    {
        $auth = $this->operatorAuth();

        $this->deleteJson('/api/v1/environments/production', [], $auth)
            ->assertForbidden()
            ->assertJsonPath('error', fn (?string $m): bool => is_string($m) && str_contains($m, 'protected'));

        $this->postJson('/api/v1/environments/production/reset', [], $auth)
            ->assertForbidden();

        // Production is untouched — still present and protected.
        $production = Environment::query()->where('key', 'production')->first();
        $this->assertNotNull($production);
        $this->assertTrue($production->protected);
    }

    public function test_an_org_scoped_token_cannot_manage_environments(): void
    {
        Organization::query()->create(['id' => 'org_x', 'name' => 'X', 'billing_country' => 'DK']);
        ['plaintext' => $orgToken] = ApiToken::issue('org-scoped', 'org_x');

        $this->getJson('/api/v1/environments', $this->bearer($orgToken))->assertForbidden();
        $this->postJson('/api/v1/environments', ['key' => 'nope'], $this->bearer($orgToken))->assertForbidden();
    }

    public function test_create_rejects_a_reserved_or_taken_key(): void
    {
        $auth = $this->operatorAuth();

        $this->postJson('/api/v1/environments', ['key' => 'production'], $auth)->assertStatus(422);

        $this->postJson('/api/v1/environments', ['key' => 'dup'], $auth)->assertCreated();
        $this->postJson('/api/v1/environments', ['key' => 'dup'], $auth)->assertStatus(422);
    }

    public function test_create_without_token_flag_returns_no_token(): void
    {
        $create = $this->postJson('/api/v1/environments', [
            'key' => 'no-token',
            'with_token' => false,
        ], $this->operatorAuth());

        $create->assertCreated();
        $this->assertNull($create->json('token'));
    }
}
