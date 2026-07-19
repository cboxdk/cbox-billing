<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\CurrentUser;
use App\Auth\OperatorAccess;
use App\Http\Middleware\EnsureOperator;
use App\Models\ApiToken;
use App\Platform\ConsoleCurrentContext;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The coarse operator-org boundary (SEC-1) — {@see EnsureOperator}. A valid
 * Cbox ID session is NOT enough to reach the provider console: the principal must belong to an
 * allowlisted operator organization (or be an allowlisted subject). It is deny-by-default
 * (fail-closed when unconfigured) and independent of the flag-gated per-permission RBAC. The
 * token-authed management API and the signed-token portal are deliberately out of its scope.
 */
class OperatorConsoleGateTest extends TestCase
{
    use RefreshDatabase;

    /** The console surfaces the boundary must protect — reads, and the API-token mint vector. */
    private const CONSOLE_ROUTES = [
        ['get', '/'],
        ['get', '/catalog'],
        ['get', '/customers'],
        ['get', '/settings'],
        ['post', '/settings/api-tokens'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        // The gate is ACTIVE for these tests (phpunit.xml configures operator orgs); each test
        // narrows the allowlist to what it needs. Default here: a single operator org.
        config()->set('billing.console.operator_orgs', ['org_ops']);
        config()->set('billing.console.operator_subjects', []);
    }

    /**
     * A signed-in Cbox ID session for the given org/sub.
     *
     * @return $this
     */
    private function signedIn(?string $org, string $sub = 'demo|user'): self
    {
        $this->withSession(['auth.user' => [
            'sub' => $sub,
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'org' => $org,
            'picture' => null,
        ]]);

        return $this;
    }

    public function test_a_non_operator_session_is_forbidden_on_every_console_route(): void
    {
        foreach (self::CONSOLE_ROUTES as [$verb, $path]) {
            $response = $this->signedIn('org_outsider')->{$verb}($path, ['name' => 'x']);

            $response->assertStatus(403);
            $response->assertSee('Not authorized for this console', false);
        }
    }

    public function test_an_operator_org_session_reaches_every_console_route(): void
    {
        foreach (self::CONSOLE_ROUTES as [$verb, $path]) {
            // The mint POST redirects (302) on success; the reads render (200). Either way it is
            // NOT the 403 the gate would produce — the boundary admitted the operator.
            $response = $this->signedIn('org_ops')->{$verb}($path, ['name' => 'ci token']);

            $this->assertContains($response->getStatusCode(), [200, 302], "{$verb} {$path} should clear the operator gate.");
        }
    }

    public function test_an_allowlisted_subject_reaches_the_console_regardless_of_org(): void
    {
        config()->set('billing.console.operator_orgs', []);
        config()->set('billing.console.operator_subjects', ['break|glass']);

        // Org is not allowlisted, but the subject is (break-glass).
        $this->signedIn('org_outsider', 'break|glass')->get('/')->assertOk();
    }

    public function test_an_empty_allowlist_denies_every_session_and_logs_an_actionable_warning(): void
    {
        config()->set('billing.console.operator_orgs', []);
        config()->set('billing.console.operator_subjects', []);

        Log::spy();

        // Even a session whose org WOULD be an operator org under a normal config is denied.
        $this->signedIn('org_ops')->get('/')->assertStatus(403);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'CBOX_BILLING_OPERATOR_ORGS'))
            ->atLeast()->once();
    }

    public function test_the_management_api_is_unaffected_by_the_console_boundary(): void
    {
        // A token-authed management-API call succeeds even though NO operator session exists —
        // the boundary gates the console session only, never the token-authed API.
        ['plaintext' => $token] = ApiToken::issue('sdk');

        $this->getJson('/api/v1/plans', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_the_api_and_portal_routes_do_not_carry_the_operator_gate(): void
    {
        foreach (['api.v1.plans.index', 'api.v1.entitlements.show', 'hosted.portal.show', 'hosted.checkout.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} is missing.");
            $this->assertNotContains('billing.operator', $route->gatherMiddleware(), "Route {$name} must NOT carry the console operator gate.");
        }
    }

    public function test_every_console_route_carries_the_operator_gate(): void
    {
        foreach (['billing.dashboard', 'billing.catalog', 'billing.customers', 'billing.settings', 'billing.settings.tokens.store'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} is missing.");
            $this->assertContains('billing.operator', $route->gatherMiddleware(), "Route {$name} must carry the console operator gate.");
        }
    }

    public function test_is_admin_reflects_operator_membership_not_mere_authentication(): void
    {
        $operators = app(OperatorAccess::class);

        $this->assertFalse($this->contextFor('org_outsider', $operators)->isAdmin());
        $this->assertTrue($this->contextFor('org_ops', $operators)->isAdmin());
    }

    public function test_the_api_token_mint_records_the_minting_operator_subject(): void
    {
        $this->signedIn('org_ops', 'demo|minter')
            ->post('/settings/api-tokens', ['name' => 'audited'])
            ->assertOk();

        $token = ApiToken::query()->where('name', 'audited')->firstOrFail();
        $this->assertSame('demo|minter', $token->created_by_sub);
    }

    private function contextFor(string $org, OperatorAccess $operators): ConsoleCurrentContext
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('auth.user', ['sub' => 'demo|user', 'name' => 'X', 'email' => 'x@example.test', 'org' => $org, 'picture' => null]);

        return new ConsoleCurrentContext(new CurrentUser($session), $operators);
    }
}
