<?php

declare(strict_types=1);

namespace Tests\Feature;

use Cbox\Console\Kit\Contracts\FeatureRegistry;
use Cbox\Console\Kit\Contracts\NavRegistry;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The console-kit plugin socket: a private plugin extends the admin console — nav areas
 * and feature-gated routes — with no edit to this open base.
 */
class ConsoleSocketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        $this->withSession(['auth.user' => [
            'sub' => 'demo|tester',
            'name' => 'Test Operator',
            'email' => 'ops@example.test',
            'org' => 'Cbox Systems',
            'picture' => null,
        ]]);
    }

    public function test_a_plugin_area_seeded_into_the_registry_renders_in_the_shell(): void
    {
        // A plugin adds its own area/page purely by registering — no edit to the base.
        $this->app->make(NavRegistry::class)
            ->area('metering-plus', 'Metering Plus', 'activity', 45)
            ->page('billing.dashboard', 'Live meters');

        $this->get('/')->assertOk()->assertSee('Metering Plus');
    }

    public function test_feature_gate_hides_a_route_until_the_feature_is_present(): void
    {
        Route::middleware(['web', 'console.feature:ghost'])->get('/__socket_probe', fn (): string => 'reachable');

        // Deny-by-default: an unregistered feature 404s its gated route.
        $this->get('/__socket_probe')->assertNotFound();

        // Present the feature (as an installed plugin's provider would) — now reachable.
        $this->app->make(FeatureRegistry::class)->register('ghost', true);
        $this->get('/__socket_probe')->assertOk()->assertSee('reachable');
    }

    public function test_base_licenses_feature_is_registered_active(): void
    {
        // The base registers its own `licenses` feature always-on, so the gated
        // issuer console stays reachable in the open app.
        $this->assertTrue($this->app->make(FeatureRegistry::class)->active('licenses'));
        $this->get('/licenses')->assertOk();
    }
}
