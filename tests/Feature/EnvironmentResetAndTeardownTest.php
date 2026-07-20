<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Mode\BillingContext;
use App\Models\Coupon;
use App\Models\Environment;
use App\Models\Experiment;
use App\Models\ExperimentConversion;
use App\Models\ExperimentImpression;
use App\Models\ExperimentVariant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PricingTable;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sandbox reset + hard destroy, and the production-protection guard on both. Reset wipes a plane's
 * transactional book while its config survives; destroy removes the plane and every one of its
 * rows; production is refused for both. The teardown scope is exercised through the real services.
 */
class EnvironmentResetAndTeardownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([EnvironmentSeeder::class, CatalogSeeder::class]);
    }

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function environment(string $key): Environment
    {
        return Environment::query()->where('key', $key)->firstOrFail();
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        return $this->context()->runInEnvironment($this->environment($key), $callback);
    }

    /** Provision a sandbox cloned from production and give it a subscribed org (a real book). */
    private function sandboxWithBook(string $key): Environment
    {
        $environment = app(CreatesEnvironments::class)->create($key, cloneFrom: $this->environment('production'))->environment;

        $this->inEnvironment($key, function (): void {
            Organization::query()->create(['id' => 'org_book', 'name' => 'Book', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
            $plan = Plan::query()->where('key', 'starter')->firstOrFail();
            Subscription::query()->create([
                'organization_id' => 'org_book',
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'seats' => 1,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        });

        return $environment;
    }

    public function test_reset_wipes_transactional_data_but_config_survives(): void
    {
        $this->sandboxWithBook('acme-test');

        $planCountBefore = $this->inEnvironment('acme-test', fn (): int => Plan::query()->count());
        $this->assertGreaterThan(0, $planCountBefore);

        $result = app(ResetsEnvironments::class)->reset($this->environment('acme-test'));

        $this->assertFalse($result->environmentRemoved);
        $this->assertGreaterThan(0, $result->totalDeleted());

        $this->inEnvironment('acme-test', function () use ($planCountBefore): void {
            // Transactional data is gone …
            $this->assertSame(0, Subscription::query()->count(), 'subscriptions wiped');
            $this->assertSame(0, Organization::query()->count(), 'customers wiped');
            // … but the config survives untouched.
            $this->assertSame($planCountBefore, Plan::query()->count(), 'catalog config survives a reset');
        });

        // The environment row itself is untouched.
        $this->assertNotNull(Environment::query()->where('key', 'acme-test')->first());
    }

    public function test_reset_keeps_the_plane_gateway_keys(): void
    {
        $environment = $this->sandboxWithBook('acme-test');
        app(EnvironmentGatewayStore::class)->put($environment, 'sk_test_keep', null, null);

        app(ResetsEnvironments::class)->reset($environment);

        $this->assertNotNull(app(EnvironmentGatewayStore::class)->forEnvironment('acme-test'), 'gateway keys survive a reset');
    }

    public function test_reset_with_reseed_replaces_config_from_the_source(): void
    {
        $environment = $this->sandboxWithBook('acme-test');

        // Diverge the sandbox config from production, then reseed from production.
        $this->inEnvironment('acme-test', function (): void {
            Plan::query()->where('key', 'starter')->firstOrFail()->update(['name' => 'Sandbox-only edit']);
        });

        app(ResetsEnvironments::class)->reset($environment, $this->environment('production'));

        $this->inEnvironment('acme-test', function (): void {
            $this->assertSame(0, Subscription::query()->count());
            // The reseed re-copied production's config, so the local divergence is gone.
            $this->assertNotSame('Sandbox-only edit', Plan::query()->where('key', 'starter')->firstOrFail()->name);
        });
    }

    public function test_reset_leaves_no_dead_coupon_binding_or_stale_experiment_counts(): void
    {
        $this->sandboxWithBook('acme-test');

        $this->inEnvironment('acme-test', function (): void {
            // A durable coupon binding on the sandbox's subscription.
            $subscription = Subscription::query()->firstOrFail();
            $coupon = Coupon::query()->create([
                'code' => 'SAVE20', 'discount_type' => 'percent', 'percent_off' => 20, 'duration' => 'forever',
            ]);
            SubscriptionCoupon::query()->create([
                'subscription_id' => $subscription->id, 'coupon_id' => $coupon->id, 'code' => 'SAVE20',
                'discount_type' => 'percent', 'percent_off' => 20, 'duration' => 'forever', 'remaining_periods' => null,
            ]);

            // A running experiment (config) with impression + conversion counters (transactional).
            $table = PricingTable::query()->create(['key' => 'pt1', 'name' => 'PT', 'default_currency' => 'DKK']);
            $experiment = Experiment::query()->create(['key' => 'exp1', 'name' => 'Exp', 'status' => 'running', 'pricing_table_id' => $table->id]);
            $variant = ExperimentVariant::query()->create(['experiment_id' => $experiment->id, 'label' => 'A', 'is_control' => true]);
            ExperimentImpression::query()->create(['experiment_id' => $experiment->id, 'experiment_variant_id' => $variant->id, 'visitor_id' => 'v1', 'first_seen_at' => now()]);
            ExperimentConversion::query()->create(['experiment_id' => $experiment->id, 'experiment_variant_id' => $variant->id, 'visitor_id' => 'v1', 'kind' => 'checkout_completed', 'converted_at' => now()]);
        });

        app(ResetsEnvironments::class)->reset($this->environment('acme-test'));

        $this->inEnvironment('acme-test', function (): void {
            // The coupon binding is gone with the wiped subscription — no dead binding survives.
            $this->assertSame(0, SubscriptionCoupon::query()->count(), 'coupon binding wiped');
            // The A/B counters are zeroed …
            $this->assertSame(0, ExperimentImpression::query()->count(), 'impressions wiped');
            $this->assertSame(0, ExperimentConversion::query()->count(), 'conversions wiped');
            // … but the experiment itself (config) survives the reset.
            $this->assertSame(1, Experiment::query()->count(), 'the experiment config survives');
        });
    }

    public function test_production_reset_is_refused(): void
    {
        $this->expectException(EnvironmentProtectedException::class);
        app(ResetsEnvironments::class)->reset($this->environment('production'));
    }

    public function test_destroy_removes_the_plane_and_all_its_rows(): void
    {
        $this->sandboxWithBook('acme-test');

        $result = app(DestroysEnvironments::class)->destroy($this->environment('acme-test'));

        $this->assertTrue($result->environmentRemoved);
        $this->assertNull(Environment::query()->where('key', 'acme-test')->first());

        // Nothing of the plane survives — config AND transactional rows are gone.
        $this->assertSame(0, Subscription::query()->withoutGlobalScopes()->where('environment', 'acme-test')->count());
        $this->assertSame(0, Plan::query()->withoutGlobalScopes()->where('environment', 'acme-test')->count());
        $this->assertSame(0, Organization::query()->withoutGlobalScopes()->where('environment', 'acme-test')->count());

        // Production is untouched.
        $this->assertGreaterThan(0, $this->inEnvironment('production', fn (): int => Plan::query()->count()));
    }

    public function test_production_destroy_is_refused(): void
    {
        $this->expectException(EnvironmentProtectedException::class);
        app(DestroysEnvironments::class)->destroy($this->environment('production'));
    }

    public function test_the_reset_command_wipes_a_sandbox_and_refuses_production(): void
    {
        $this->sandboxWithBook('acme-test');

        $this->artisan('environment:reset', ['key' => 'acme-test'])->assertSuccessful();
        $this->inEnvironment('acme-test', function (): void {
            $this->assertSame(0, Subscription::query()->count());
            $this->assertGreaterThan(0, Plan::query()->count());
        });

        // Production is refused (non-zero exit, no wipe).
        $this->artisan('environment:reset', ['key' => 'production'])->assertFailed();
        $this->assertGreaterThan(0, $this->inEnvironment('production', fn (): int => Plan::query()->count()));
    }
}
