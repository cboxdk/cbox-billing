<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\EnvironmentType;
use App\Billing\Environments\GatewayKeyMode;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\EnvironmentGateway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console Environments management area (Settings): list planes, create a sandbox (optionally
 * cloning config), reset a sandbox's book, destroy a sandbox, and enter per-environment gateway
 * keys with the test-vs-live gate. Production is refused for the destructive actions in the UI too.
 */
class EnvironmentsConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    /** @param array<string, mixed> $extra */
    private function sessionWith(array $extra = []): array
    {
        return array_merge($this->session, $extra);
    }

    public function test_the_environments_page_lists_the_planes(): void
    {
        $this->withSession($this->session)->get('/environments')
            ->assertOk()
            ->assertSee('production')
            ->assertSee('sandbox')
            ->assertSee('New sandbox');
    }

    public function test_creating_a_cloned_sandbox_copies_config_and_switches_to_it(): void
    {
        $this->withSession($this->session)
            ->post('/environments', ['key' => 'acme-test', 'name' => 'Acme Test', 'clone_from' => 'production'])
            ->assertRedirect(route('billing.environments'))
            ->assertSessionHas('status')
            ->assertSessionHas('console.environment', 'acme-test');

        $environment = Environment::query()->where('key', 'acme-test')->first();
        $this->assertNotNull($environment);
        $this->assertFalse($environment->protected);

        // The clone carried production's catalog into the new plane.
        app(BillingContext::class)->runInEnvironment($environment, function (): void {
            $this->assertGreaterThan(0, Plan::query()->count());
        });
    }

    public function test_resetting_a_sandbox_wipes_the_book_but_keeps_config_from_the_console(): void
    {
        $context = app(BillingContext::class);
        $sandbox = Environment::query()->where('key', 'sandbox')->firstOrFail();
        $context->runInEnvironment($sandbox, function (): void {
            $this->seed(CatalogSeeder::class);
            Organization::query()->create(['id' => 'org_s', 'name' => 'S', 'billing_country' => 'DK']);
            $plan = Plan::query()->where('key', 'starter')->firstOrFail();
            Subscription::query()->create([
                'organization_id' => 'org_s', 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
                'seats' => 1, 'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
            ]);
        });

        $this->withSession($this->session)
            ->post('/environments/sandbox/reset')
            ->assertRedirect(route('billing.environments'))
            ->assertSessionHas('status');

        $context->runInEnvironment($sandbox, function (): void {
            $this->assertSame(0, Subscription::query()->count());
            $this->assertGreaterThan(0, Plan::query()->count(), 'config survives');
        });
    }

    public function test_destroying_a_sandbox_removes_it_from_the_console(): void
    {
        Environment::query()->create([
            'key' => 'throwaway', 'name' => 'Throwaway',
            'type' => EnvironmentType::Sandbox, 'protected' => false,
            'gateway_key_mode' => GatewayKeyMode::Test,
        ]);

        $this->withSession($this->session)
            ->delete('/environments/throwaway')
            ->assertRedirect(route('billing.environments'))
            ->assertSessionHas('status');

        $this->assertNull(Environment::query()->where('key', 'throwaway')->first());
    }

    public function test_the_console_refuses_to_destroy_or_reset_production(): void
    {
        $this->withSession($this->session)->delete('/environments/production')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->withSession($this->session)->post('/environments/production/reset')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(Environment::query()->where('key', 'production')->first());
    }

    public function test_gateway_keys_are_key_type_gated_per_environment(): void
    {
        // Active plane = production (default): a test key is refused.
        $this->withSession($this->session)
            ->post('/settings/gateways', ['secret' => 'sk_test_wrong'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull(EnvironmentGateway::query()->where('environment', 'production')->first());

        // A live key is accepted for production.
        $this->withSession($this->session)
            ->post('/settings/gateways', ['secret' => 'sk_live_ok', 'publishable' => 'pk_live_ok'])
            ->assertRedirect(route('billing.settings.gateways'))
            ->assertSessionHas('status');
        $this->assertNotNull(EnvironmentGateway::query()->where('environment', 'production')->first());

        // Active plane = sandbox: a test key is accepted, a live key refused.
        $sandboxSession = $this->sessionWith(['console.environment' => 'sandbox']);

        $this->withSession($sandboxSession)
            ->post('/settings/gateways', ['secret' => 'sk_live_wrong'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->withSession($sandboxSession)
            ->post('/settings/gateways', ['secret' => 'sk_test_ok'])
            ->assertRedirect(route('billing.settings.gateways'))
            ->assertSessionHas('status');
        $this->assertNotNull(EnvironmentGateway::query()->where('environment', 'sandbox')->first());
    }
}
