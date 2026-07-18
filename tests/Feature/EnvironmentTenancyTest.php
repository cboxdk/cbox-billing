<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthedUser;
use App\Models\Organization;
use App\Platform\EnvironmentContext;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Per-environment tenancy: the principal reads the environment claims when present, the org
 * records its home environment (stamped on login, default when no claim, recorded value kept
 * on a mismatch), and the console chip surfaces the active plane. Additive + BC — a login
 * without the claim behaves exactly as a single-environment deployment.
 */
class EnvironmentTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_authed_user_reads_the_environment_claims_when_present(): void
    {
        $user = AuthedUser::fromClaims([
            'sub' => 'user_1',
            'org' => 'org_1',
            'environment' => 'env_01hprod',
            'environment_name' => 'Production EU',
        ]);

        $this->assertSame('env_01hprod', $user->environment);
        $this->assertSame('Production EU', $user->environmentName);
        $this->assertSame('Production EU', $user->environmentLabel());
    }

    public function test_authed_user_has_no_environment_without_the_claim(): void
    {
        $user = AuthedUser::fromClaims(['sub' => 'user_1', 'org' => 'org_1']);

        $this->assertNull($user->environment);
        $this->assertNull($user->environmentName);
        $this->assertNull($user->environmentLabel());

        // Round-trips through the session array.
        $this->assertNull(AuthedUser::fromArray($user->toArray())->environment);
    }

    public function test_stamp_records_the_default_when_the_login_carries_no_claim(): void
    {
        $org = Organization::query()->create(['id' => 'org_new', 'name' => 'New Co']);
        $this->assertNull($org->environment_key);

        app(EnvironmentContext::class)->stamp($this->user('org_new', environment: null));

        $this->assertSame('default', $org->refresh()->environment_key);
    }

    public function test_stamp_records_the_claimed_environment(): void
    {
        Organization::query()->create(['id' => 'org_prod', 'name' => 'Prod Co']);

        app(EnvironmentContext::class)->stamp($this->user('org_prod', environment: 'env_01hprod'));

        $this->assertSame('env_01hprod', Organization::query()->findOrFail('org_prod')->environment_key);
    }

    public function test_stamp_keeps_the_recorded_environment_on_a_mismatch(): void
    {
        Organization::query()->create(['id' => 'org_pinned', 'name' => 'Pinned Co', 'environment_key' => 'env_a']);

        Log::spy();

        app(EnvironmentContext::class)->stamp($this->user('org_pinned', environment: 'env_b'));

        $this->assertSame('env_a', Organization::query()->findOrFail('org_pinned')->environment_key);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_console_chip_renders_the_active_environment(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        $response = $this->withSession(['auth.user' => [
            'sub' => 'demo|op',
            'name' => 'Op',
            'email' => 'op@example.test',
            'org' => 'org_hverdag',
            'picture' => null,
            'environment' => 'env_01hprod',
            'environment_name' => 'Production EU',
        ]])->get('/');

        $response->assertOk();
        $response->assertSee('data-env-chip', false);
        $response->assertSee('Production EU', false);
    }

    public function test_console_chip_falls_back_to_the_configured_default(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        config()->set('cbox-id-client.environment_default', 'prod-eu');

        $response = $this->withSession(['auth.user' => [
            'sub' => 'demo|op',
            'name' => 'Op',
            'email' => 'op@example.test',
            'org' => 'org_hverdag',
            'picture' => null,
        ]])->get('/');

        $response->assertOk();
        $response->assertSee('prod-eu', false);
    }

    private function user(string $org, ?string $environment): AuthedUser
    {
        return new AuthedUser(
            sub: 'user_1',
            name: 'User One',
            email: 'u@example.test',
            org: $org,
            picture: null,
            environment: $environment,
        );
    }
}
