<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Models\Coupon;
use App\Models\DunningStrategy;
use App\Models\Environment;
use App\Models\Meter;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Env Wave 2 (A): the CONFIG surface is environment-scoped. Every config natural key that used to
 * be GLOBALLY unique is now unique WITHIN an environment, so a sandbox can own its own `pro` plan
 * / `WELCOME` coupon / `api_calls` meter / `hard_decline` dunning strategy without colliding with
 * production — and each plane reads only its own config. Production is the default plane, so the
 * seeded catalog stays exactly where every existing test resolves it.
 */
class ConfigEnvironmentScopingTest extends TestCase
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

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return $this->context()->runInEnvironment($environment, $callback);
    }

    public function test_the_seeded_catalog_lands_in_production(): void
    {
        // Default plane is production, so the seeder wrote there and reads resolve there.
        $this->assertGreaterThan(0, Plan::query()->count());
        $this->assertSame('production', Plan::query()->first()?->getAttribute('environment'));
    }

    public function test_a_second_environment_can_hold_the_same_natural_keys(): void
    {
        // A sandbox plane authors its OWN config carrying keys that already exist in production.
        Environment::query()->create([
            'key' => 'sbx', 'name' => 'Sandbox X', 'type' => 'sandbox',
            'protected' => false, 'gateway_key_mode' => 'test',
        ]);

        $this->inEnvironment('sbx', function (): void {
            $product = Product::query()->create(['key' => 'cbox-billing', 'name' => 'Sandbox product']);
            Plan::query()->create(['product_id' => $product->id, 'key' => 'starter', 'name' => 'Sandbox Starter']);
            Meter::query()->create(['key' => 'api_calls', 'name' => 'API calls', 'unit' => 'call']);
            Coupon::query()->create([
                'code' => 'WELCOME', 'discount_type' => 'percent', 'percent_off' => 10,
                'duration' => 'once', 'applies_to' => 'all', 'active' => true,
            ]);
            DunningStrategy::query()->create(['category' => 'hard_decline', 'backoff_days' => [1, 3]]);
        });

        // These same keys also exist in production (seeded) — no unique collision across planes.
        $this->inEnvironment(Environment::PRODUCTION, function (): void {
            Coupon::query()->create([
                'code' => 'WELCOME', 'discount_type' => 'percent', 'percent_off' => 20,
                'duration' => 'once', 'applies_to' => 'all', 'active' => true,
            ]);
            DunningStrategy::query()->create(['category' => 'hard_decline', 'backoff_days' => [2, 4]]);
        });

        // Raw rows: each key exists once per plane (two total), proving per-environment uniqueness.
        $this->assertSame(2, Plan::query()->withoutGlobalScopes()->where('key', 'starter')->count());
        $this->assertSame(2, Coupon::query()->withoutGlobalScopes()->where('code', 'WELCOME')->count());
        $this->assertSame(2, DunningStrategy::query()->withoutGlobalScopes()->where('category', 'hard_decline')->count());

        // And each plane resolves ONLY its own config value.
        $sandboxCoupon = $this->inEnvironment('sbx', fn (): ?Coupon => Coupon::query()->where('code', 'WELCOME')->first());
        $productionCoupon = $this->inEnvironment(Environment::PRODUCTION, fn (): ?Coupon => Coupon::query()->where('code', 'WELCOME')->first());
        $this->assertSame(10, $sandboxCoupon?->percent_off);
        $this->assertSame(20, $productionCoupon?->percent_off);
    }

    public function test_config_reads_are_confined_to_the_active_plane(): void
    {
        Environment::query()->create([
            'key' => 'empty', 'name' => 'Empty', 'type' => 'sandbox',
            'protected' => false, 'gateway_key_mode' => 'test',
        ]);

        // The empty plane sees NONE of production's seeded catalog (deny-by-default scoping).
        $this->inEnvironment('empty', function (): void {
            $this->assertSame(0, Plan::query()->count());
            $this->assertSame(0, Product::query()->count());
            $this->assertSame(0, Meter::query()->count());
        });

        // Production is untouched.
        $this->assertGreaterThan(0, Plan::query()->count());
    }
}
