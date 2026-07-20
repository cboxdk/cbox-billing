<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Http\Middleware\EnsureAuthenticated;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureSandboxPlane;
use App\Http\Middleware\ResolveConsoleMode;
use App\Models\Coupon;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\TestClock;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P1 — PLANE BEFORE BINDING. `billing.mode` (ResolveConsoleMode) is a ROUTE middleware on the console
 * group, but `SubstituteBindings` ships in the `web` GROUP — and group middleware runs ahead of route
 * middleware. So every implicit model binding on a console route ({subscription}, {testClock},
 * {invoice}, {plan}, {coupon}, …) was substituted under the ambient PRODUCTION plane regardless of the
 * environment the operator had actually selected in the switcher.
 *
 * Two consequences, both proved below:
 *  1. USABILITY — an operator switched to a named sandbox 404s on that sandbox's own model-bound
 *     pages, because the binding looked the row up under production scope.
 *  2. SAFETY — with a PRODUCTION id in hand, a mutating console action bound and mutated the
 *     PRODUCTION row while the operator believed they were acting inside a sandbox.
 *
 * The fix orders `EnsureAuthenticated → EnsureOperator → ResolveConsoleMode → SubstituteBindings`
 * through Laravel's middleware PRIORITY list (bootstrap/app.php), which is the only mechanism that
 * orders middleware across the group/route boundary. These tests assert the OUTCOME (both directions
 * of the isolation) and, as a structural backstop, the resolved order on every model-bound console
 * route — so a future middleware edit that silently drops the priority chain fails here.
 */
class ConsoleBindingPlaneOrderTest extends TestCase
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

        app(CreatesEnvironments::class)->create(
            key: 'ci-plane',
            cloneFrom: Environment::query()->where('key', 'production')->firstOrFail(),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The console session for an operator whose switcher sits on `$environment`. */
    private function consoleSession(string $environment): array
    {
        return ['console.environment' => $environment] + $this->auth;
    }

    /** @template T @param callable(): T $work @return T */
    private function inPlane(string $key, callable $work): mixed
    {
        return app(BillingContext::class)->runInEnvironment(
            Environment::query()->where('key', $key)->firstOrFail(),
            $work,
        );
    }

    private function clock(string $environment, string $name): TestClock
    {
        return $this->inPlane($environment, fn (): TestClock => TestClock::query()->create([
            'name' => $name, 'now_at' => now(), 'status' => 'ready',
        ]));
    }

    private function invoice(string $environment, string $number): Invoice
    {
        return $this->inPlane($environment, function () use ($environment, $number): Invoice {
            // Organization ids are globally unique, so each plane gets its own.
            $org = 'org_bind_'.str_replace('-', '_', $environment);
            Organization::query()->firstOrCreate(['id' => $org], ['name' => 'Bind', 'billing_country' => 'DK']);

            return Invoice::query()->create([
                'organization_id' => $org, 'seller' => 'seller_x', 'number' => $number, 'currency' => 'EUR',
                'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
            ]);
        });
    }

    private function coupon(string $environment, string $code): Coupon
    {
        return $this->inPlane($environment, fn (): Coupon => Coupon::query()->create([
            'code' => $code, 'name' => $code, 'discount_type' => 'percent', 'percent_off' => 10,
            'duration' => 'once', 'active' => true,
        ]));
    }

    /**
     * DIRECTION 1 — a model-bound console GET must FIND the selected sandbox's record. Before the
     * fix the binding ran under production scope and this 404'd.
     */
    public function test_a_model_bound_console_get_finds_the_selected_sandboxs_record(): void
    {
        $sandboxClock = $this->clock('ci-plane', 'Sandbox clock');
        $sandboxInvoice = $this->invoice('ci-plane', 'CBOX-DK-2026-00001');
        $sandboxCoupon = $this->coupon('ci-plane', 'SANDBOXONLY');

        $session = $this->consoleSession('ci-plane');

        $this->withSession($session)->get(route('billing.test-mode.clocks.show', $sandboxClock))->assertOk();
        $this->withSession($session)->get(route('billing.invoices.show', $sandboxInvoice))->assertOk();
        $this->withSession($session)->get(route('billing.coupons.show', $sandboxCoupon))->assertOk();
    }

    /**
     * DIRECTION 2 — from a sandbox session, a PRODUCTION id must not bind at all. This is the safety
     * half: before the fix the production row bound and the page rendered production data.
     */
    public function test_a_sandbox_session_cannot_bind_a_production_record(): void
    {
        $productionClock = $this->clock('production', 'Production clock');
        $productionInvoice = $this->invoice('production', 'CBOX-DK-2026-00009');
        $productionCoupon = $this->coupon('production', 'PRODONLY');

        $session = $this->consoleSession('ci-plane');

        $this->withSession($session)->get(route('billing.test-mode.clocks.show', $productionClock))->assertNotFound();
        $this->withSession($session)->get(route('billing.invoices.show', $productionInvoice))->assertNotFound();
        $this->withSession($session)->get(route('billing.coupons.show', $productionCoupon))->assertNotFound();
    }

    /**
     * The SAFETY case in full: a mutating console action driven from a sandbox session, aimed at a
     * PRODUCTION id, must be denied AND leave the production row untouched.
     */
    public function test_a_sandbox_session_cannot_mutate_a_production_record(): void
    {
        $productionInvoice = $this->invoice('production', 'CBOX-DK-2026-00010');
        $productionCoupon = $this->coupon('production', 'PRODMUTATE');
        $productionClock = $this->clock('production', 'Production clock');

        $session = $this->consoleSession('ci-plane');

        $this->withSession($session)->post(route('billing.invoices.void', $productionInvoice))->assertNotFound();
        $this->withSession($session)->post(route('billing.coupons.archive', $productionCoupon))->assertNotFound();
        $this->withSession($session)
            ->post(route('billing.test-mode.clocks.advance', $productionClock), ['target' => '2026-02-15T00:00'])
            ->assertNotFound();

        // Nothing moved in production.
        $this->assertSame(
            InvoiceStatus::Open->value,
            (string) Invoice::query()->withoutGlobalScopes()->findOrFail($productionInvoice->id)->getAttribute('status')->value,
        );
        $this->assertTrue((bool) Coupon::query()->withoutGlobalScopes()->findOrFail($productionCoupon->id)->getAttribute('active'));
        $this->assertTrue(
            Carbon::parse('2026-01-01 00:00:00')->equalTo(
                TestClock::query()->withoutGlobalScopes()->findOrFail($productionClock->id)->getAttribute('now_at'),
            ),
            'the production clock must not have been advanced from a sandbox session',
        );
    }

    /** The MIRROR: a production session must not bind a sandbox record, and must still bind its own. */
    public function test_a_production_session_binds_production_and_not_the_sandbox(): void
    {
        $productionInvoice = $this->invoice('production', 'CBOX-DK-2026-00011');
        $sandboxInvoice = $this->invoice('ci-plane', 'CBOX-DK-2026-00012');

        $session = $this->consoleSession('production');

        $this->withSession($session)->get(route('billing.invoices.show', $productionInvoice))->assertOk();
        $this->withSession($session)->get(route('billing.invoices.show', $sandboxInvoice))->assertNotFound();
    }

    /**
     * STRUCTURAL BACKSTOP — sweep EVERY console route that carries a route parameter and assert the
     * resolved middleware pipeline puts authentication, the operator-org gate and the plane resolver
     * ahead of binding substitution. This is what makes the two behavioural tests above general
     * rather than anecdotal: it covers all ~159 model-bound console routes at once.
     */
    public function test_every_model_bound_console_route_resolves_the_plane_before_binding(): void
    {
        // Resolving the HTTP kernel is what syncs the middleware groups, aliases and PRIORITY list
        // onto the router — without it `gatherRouteMiddleware()` hands back unresolved group names.
        app(HttpKernel::class);

        $router = Route::getFacadeRoot();
        $checked = 0;

        foreach ($router->getRoutes() as $route) {
            $pipeline = $router->gatherRouteMiddleware($route);

            if ($route->parameterNames() === [] || ! in_array(ResolveConsoleMode::class, $pipeline, true)) {
                continue;
            }

            $at = static fn (string $middleware): int|false => array_search($middleware, $pipeline, true);

            $bindings = $at(SubstituteBindings::class);
            $this->assertIsInt($bindings, "{$route->uri()} must substitute bindings");
            $this->assertIsInt($auth = $at(EnsureAuthenticated::class));
            $this->assertIsInt($operator = $at(EnsureOperator::class));
            $this->assertIsInt($plane = $at(ResolveConsoleMode::class));

            $this->assertTrue(
                $auth < $operator && $operator < $plane && $plane < $bindings,
                "{$route->uri()} must run auth → operator → plane BEFORE binding substitution, got: "
                    .implode(' > ', array_map(static fn (string $m): string => class_basename($m), $pipeline)),
            );

            // Any middleware that CHANGES the plane must also land ahead of binding substitution —
            // the test-clock routes' `billing.sandbox` fallback is the one such case in the console.
            if (is_int($sandbox = $at(EnsureSandboxPlane::class))) {
                $this->assertTrue(
                    $plane < $sandbox && $sandbox < $bindings,
                    "{$route->uri()} must force the sandbox plane after ResolveConsoleMode and before bindings",
                );
            }

            $checked++;
        }

        $this->assertGreaterThan(100, $checked, 'the sweep must actually cover the console surface');
    }
}
