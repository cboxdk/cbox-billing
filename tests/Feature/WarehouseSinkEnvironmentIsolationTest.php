<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\WarehouseSink;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A warehouse sink is operator infrastructure of ONE plane: a sink configured while switched to a
 * sandbox is isolated to that sandbox (invisible in production) and is removed when the sandbox is
 * destroyed — it is not a global/production sink.
 */
class WarehouseSinkEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    private function makeSink(string $key): void
    {
        WarehouseSink::query()->create([
            'key' => $key,
            'name' => 'Sink '.$key,
            'warehouse' => 'snowflake',
            'disk' => 'local',
            'prefix' => 'exports',
            'format' => 'ndjson',
            'datasets' => ['invoices'],
        ]);
    }

    public function test_a_sandbox_sink_is_invisible_in_production_and_removed_on_destroy(): void
    {
        $environment = app(CreatesEnvironments::class)->create(key: 'sbx-sink')->environment;

        // A production sink and a sandbox sink, each created in its own plane.
        $this->inEnvironment('production', fn () => $this->makeSink('prod_sink'));
        $this->inEnvironment('sbx-sink', fn () => $this->makeSink('sandbox_sink'));

        // Production sees only its own sink; the sandbox sees only its own.
        $this->assertSame(['prod_sink'], $this->inEnvironment('production', fn (): array => WarehouseSink::query()->pluck('key')->all()));
        $this->assertSame(['sandbox_sink'], $this->inEnvironment('sbx-sink', fn (): array => WarehouseSink::query()->pluck('key')->all()));

        // Destroying the sandbox removes its sink; production's survives.
        app(DestroysEnvironments::class)->destroy($environment);

        $this->assertSame(0, WarehouseSink::query()->withoutGlobalScopes()->where('environment', 'sbx-sink')->count());
        $this->assertSame(1, WarehouseSink::query()->withoutGlobalScopes()->where('environment', 'production')->count());
    }

    public function test_the_same_sink_key_is_unique_per_plane_not_globally(): void
    {
        // Finding 7 (P2): the sink `key` is unique per (key, environment), so two planes can each own
        // a sink named the same — but a duplicate within ONE plane is still refused.
        app(CreatesEnvironments::class)->create(key: 'sbx-key');

        $this->inEnvironment('production', fn () => $this->makeSink('analytics'));
        $this->inEnvironment('sbx-key', fn () => $this->makeSink('analytics'));

        $this->assertSame(1, WarehouseSink::query()->withoutGlobalScopes()->where('key', 'analytics')->where('environment', 'production')->count());
        $this->assertSame(1, WarehouseSink::query()->withoutGlobalScopes()->where('key', 'analytics')->where('environment', 'sbx-key')->count());

        // A second `analytics` in the SAME plane violates the (key, environment) unique index.
        $this->expectException(QueryException::class);
        $this->inEnvironment('production', fn () => $this->makeSink('analytics'));
    }
}
