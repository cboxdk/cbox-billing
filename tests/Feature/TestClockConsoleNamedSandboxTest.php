<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Mode\BillingContext;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TestClock;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Finding 3 (P2) — the CONSOLE test-clock flows must run in the currently-selected environment. They
 * used to force the default sandbox (`setMode(Test)`), collapsing a NAMED sandbox onto the default
 * one — so a clock advance in a named sandbox saw none of that sandbox's subscriptions. Driven
 * through the real console routes: with the console switched to a named sandbox, creating + binding +
 * advancing a clock processes THAT sandbox's subscription.
 */
class TestClockConsoleNamedSandboxTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $auth = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_console_clock_advance_processes_the_selected_named_sandboxs_subscriptions(): void
    {
        // A named sandbox cloned from production (so it carries the catalog), with a subscribed org.
        app(CreatesEnvironments::class)->create(key: 'ci-console', cloneFrom: Environment::query()->where('key', 'production')->firstOrFail());

        $subscriptionId = app(BillingContext::class)->runInEnvironment(
            Environment::query()->where('key', 'ci-console')->firstOrFail(),
            function (): int {
                $org = Organization::query()->create(['id' => 'org_ci', 'name' => 'CI', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
                $plan = Plan::query()->where('key', 'starter')->with('prices', 'product')->firstOrFail();

                return app(SubscribesOrganizations::class)->subscribe($org, $plan)->id;
            },
        );

        // The console is switched to the named sandbox for every request.
        $session = ['console.environment' => 'ci-console'] + $this->auth;

        // Create the clock through the console — it must land in the SELECTED sandbox, not the default.
        $this->withSession($session)->post('/test-mode/clocks', ['name' => 'Scenario', 'now_at' => '2026-01-01T00:00'])->assertRedirect();
        $clock = TestClock::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ci-console', $clock->getAttribute('environment'), 'the console clock belongs to the selected named sandbox');

        // Bind the sandbox subscription and advance — both through the console routes.
        $this->withSession($session)->post(route('billing.test-mode.clocks.bind', $clock), ['subscription_id' => $subscriptionId])->assertRedirect();
        $this->withSession($session)->post(route('billing.test-mode.clocks.advance', $clock), ['target' => '2026-02-15T00:00'])->assertRedirect();

        // The advance ran in 'ci-console': its subscription renewed (an invoice landed in that plane).
        $this->assertGreaterThanOrEqual(
            1,
            Invoice::query()->withoutGlobalScopes()->where('environment', 'ci-console')->where('subscription_id', $subscriptionId)->count(),
            'the named-sandbox clock advance must process that sandbox subscription',
        );

        // Nothing leaked into the default sandbox.
        $this->assertSame(0, Subscription::query()->withoutGlobalScopes()->where('environment', 'sandbox')->count());
    }
}
