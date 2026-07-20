<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Mode\BillingContext;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\TestMode\TestClockAdvancer;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TestClock;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A test clock bound to a NAMED sandbox advances THAT sandbox's subscriptions. Before the fix the
 * advancer forced the default sandbox plane, so a clock in a named sandbox no-op'd (its
 * subscriptions were invisible). Now the advance runs in the clock's own plane.
 */
class TestClockNamedEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        // Production owns the seeded catalog; the named sandbox is cloned from it below.
        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    public function test_a_clock_in_a_named_sandbox_advances_that_sandboxs_subscriptions(): void
    {
        // A CI sandbox cloned from production (so it has the catalog).
        app(CreatesEnvironments::class)->create(key: 'ci-clock', cloneFrom: Environment::query()->where('key', 'production')->firstOrFail());

        [$subscriptionId, $clockId] = $this->inEnvironment('ci-clock', function (): array {
            $org = Organization::query()->create([
                'id' => 'org_ci_clock',
                'name' => 'CI Clock',
                'billing_country' => 'DK',
                'billing_email' => 'billing@ci-clock.test',
            ]);

            $plan = Plan::query()->where('key', 'starter')->with('prices', 'product')->firstOrFail();
            $subscription = app(SubscribesOrganizations::class)->subscribe($org, $plan);

            $clock = TestClock::query()->create([
                'name' => 'ci-clock-1',
                'now_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            ]);
            $subscription->forceFill(['test_clock_id' => $clock->id])->save();

            return [$subscription->id, $clock->id];
        });

        // The clock and subscription are stamped with the named plane, not the default sandbox.
        $this->assertSame('ci-clock', TestClock::query()->withoutGlobalScopes()->findOrFail($clockId)->getAttribute('environment'));

        // Advancing the clock IN its own plane fires exactly one monthly renewal for its subscription.
        $result = $this->inEnvironment('ci-clock', function () use ($clockId): object {
            $clock = TestClock::query()->findOrFail($clockId);

            return app(TestClockAdvancer::class)->advance($clock, CarbonImmutable::parse('2026-02-15 00:00:00', 'UTC'));
        });

        $this->assertSame(1, $result->renewals, 'the named-sandbox clock must process its own subscriptions');

        // The renewal invoice landed in the named sandbox (never the default sandbox).
        $this->assertGreaterThanOrEqual(1, Invoice::query()->withoutGlobalScopes()->where('environment', 'ci-clock')->where('subscription_id', $subscriptionId)->count());
        $this->assertSame(0, Subscription::query()->withoutGlobalScopes()->where('environment', 'sandbox')->count());
    }
}
