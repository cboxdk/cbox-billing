<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Models\WarehouseSink;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P2 — the warehouse-sink `key` validator was still GLOBALLY unique even though the DB index had
 * already moved to `(key, environment)`. So an operator switched to a sandbox could not create a
 * sink named `analytics` when production already had one — the plane partition was enforced in the
 * schema but contradicted by the console's own validation.
 *
 * Driven through the real console routes, in both directions: the same key is accepted in a second
 * environment, and a duplicate WITHIN one environment is still refused.
 */
class WarehouseSinkKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'sbx-keys';

    /** @var array<string, mixed> */
    private array $auth = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
        app(CreatesEnvironments::class)->create(key: self::SANDBOX);

        // The sink `disk` is allow-listed deny-by-default; put the test disk on the list.
        config(['billing.export.allowed_disks' => ['wh'], 'billing.export.default_disk' => 'wh']);
        config(['filesystems.disks.wh' => ['driver' => 'local', 'root' => storage_path('framework/testing/wh')]]);
    }

    /** @return array<string, mixed> */
    private function consoleSession(string $environment): array
    {
        return ['console.environment' => $environment] + $this->auth;
    }

    /** @return array<string, mixed> */
    private function payload(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Analytics '.$key,
            'warehouse' => 'snowflake',
            'disk' => 'wh',
            'prefix' => 'exports',
            'format' => 'ndjson',
            'datasets' => ['invoices'],
        ];
    }

    private function createSink(string $environment, string $key): TestResponse
    {
        return $this->withSession($this->consoleSession($environment))
            ->post(route('billing.exports.warehouse.store'), $this->payload($key));
    }

    /** The same sink key may exist in two environments — the partition the DB index already allows. */
    public function test_the_same_sink_key_can_exist_in_two_environments(): void
    {
        $this->createSink('production', 'analytics')->assertSessionHasNoErrors()->assertRedirect();
        $this->createSink(self::SANDBOX, 'analytics')->assertSessionHasNoErrors()->assertRedirect();

        $sinks = WarehouseSink::query()->withoutGlobalScopes()->where('key', 'analytics')->get();

        $this->assertCount(2, $sinks);
        $this->assertEqualsCanonicalizing(
            ['production', self::SANDBOX],
            $sinks->pluck('environment')->all(),
        );
    }

    /** The other direction: a duplicate key WITHIN one environment is still refused. */
    public function test_a_duplicate_sink_key_within_one_environment_is_still_refused(): void
    {
        $this->createSink(self::SANDBOX, 'analytics')->assertSessionHasNoErrors();
        $this->createSink(self::SANDBOX, 'analytics')->assertSessionHasErrors('key');

        $this->assertSame(1, WarehouseSink::query()->withoutGlobalScopes()
            ->where('environment', self::SANDBOX)->where('key', 'analytics')->count());
    }

    /** …and in production too, so the scoping did not simply disable the uniqueness check. */
    public function test_a_duplicate_sink_key_within_production_is_still_refused(): void
    {
        $this->createSink('production', 'analytics')->assertSessionHasNoErrors();
        $this->createSink('production', 'analytics')->assertSessionHasErrors('key');

        $this->assertSame(1, WarehouseSink::query()->withoutGlobalScopes()
            ->where('environment', 'production')->where('key', 'analytics')->count());
    }

    /** Editing a sink must not trip over its OWN key (the `ignore()` still applies per plane). */
    public function test_a_sink_can_be_updated_without_colliding_with_its_own_key(): void
    {
        $this->createSink(self::SANDBOX, 'analytics')->assertSessionHasNoErrors();
        $this->createSink('production', 'analytics')->assertSessionHasNoErrors();

        $sink = WarehouseSink::query()->withoutGlobalScopes()
            ->where('environment', self::SANDBOX)->where('key', 'analytics')->firstOrFail();

        $this->withSession($this->consoleSession(self::SANDBOX))
            ->post(route('billing.exports.warehouse.update', $sink), ['name' => 'Renamed'] + $this->payload('analytics'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Renamed', WarehouseSink::query()->withoutGlobalScopes()->findOrFail($sink->id)->name);
    }
}
