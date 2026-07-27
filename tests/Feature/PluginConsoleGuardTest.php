<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `platform.auth` is the contract between this app and every plugin.
 *
 * All six commercial plugins gate their console routes on it, with a bare `loadRoutesFrom()`
 * and no group of their own. It was registered nowhere — not in this app, not in any vendored
 * package — so installing a plugin threw `InvalidArgumentException` on every console request.
 * The whole paid tier was unreachable in a fresh composition, and because no plugin test stubs
 * the alias, nothing anywhere caught it.
 *
 * These tests declare a plugin-SHAPED route (exactly how a plugin declares one) and assert the
 * guard both resolves and actually guards. Without the first assertion the alias could be
 * registered as something inert and still "pass".
 */
class PluginConsoleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Declared the way a plugin's routes/<plugin>.php declares it.
        Route::middleware(['platform.auth'])->group(function (): void {
            Route::get('/__plugin-probe', fn (): string => 'reached')->name('plugin.probe');
        });
    }

    public function test_the_platform_auth_guard_resolves_at_all(): void
    {
        // The regression: an unregistered middleware name raises InvalidArgumentException while
        // resolving the route, which surfaces as a 500 rather than a redirect or a 403. Any
        // non-500 response proves the guard exists and ran.
        $response = $this->get('/__plugin-probe');

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'platform.auth must be registered — a plugin route cannot resolve without it.',
        );
    }

    public function test_an_unauthenticated_visitor_never_reaches_a_plugin_page(): void
    {
        $response = $this->get('/__plugin-probe');

        $response->assertRedirect();
        $this->assertStringNotContainsString('reached', $response->getContent() ?: '');
    }

    public function test_a_principal_outside_an_operator_org_is_refused(): void
    {
        // A valid session is not enough: the coarse operator-org gate must apply to a plugin
        // page exactly as it does to a first-party console page. Empty allowlist = deny-all.
        config(['billing.console.operator_orgs' => []]);

        Organization::query()->create(['id' => 'org_guard', 'name' => 'Guarded']);

        $response = $this->withSession([
            'cbox_id_user' => ['sub' => 'user_1', 'name' => 'Test', 'email' => 't@example.test', 'org' => 'org_guard'],
        ])->get('/__plugin-probe');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('reached', $response->getContent() ?: '');
    }
}
