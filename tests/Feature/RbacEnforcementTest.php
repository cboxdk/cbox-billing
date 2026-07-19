<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnforcePermission;
use App\Models\Invoice;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The federated-RBAC gate ({@see EnforcePermission}). It is defensive: inert while the flag
 * is off (so it can never lock out the console before Cbox ID emits a permissions claim),
 * and strict deny-by-default once enabled. Also asserts the route→permission map covers the
 * sensitive management surfaces.
 */
class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_flag_off_is_inert_for_holder_and_non_holder(): void
    {
        config()->set('billing.rbac.enforce', false);

        // A principal with NO permissions still reaches a catalog:manage surface.
        $this->signedInWith([])->get(route('billing.catalog.prices.create'))->assertOk();

        // A holder does too.
        $this->signedInWith(['catalog:manage'])->get(route('billing.catalog.prices.create'))->assertOk();
    }

    public function test_strict_mode_allows_the_holder(): void
    {
        config()->set('billing.rbac.enforce', true);

        $this->signedInWith(['catalog:manage'])
            ->get(route('billing.catalog.prices.create'))
            ->assertOk();
    }

    public function test_strict_mode_forbids_a_non_holder(): void
    {
        config()->set('billing.rbac.enforce', true);

        // Carries a real slug, but not the one this route requires.
        $this->signedInWith(['subscriptions:read'])
            ->get(route('billing.catalog.prices.create'))
            ->assertStatus(403);
    }

    public function test_strict_mode_lets_a_read_holder_view_a_read_page(): void
    {
        config()->set('billing.rbac.enforce', true);

        $this->signedInWith(['subscriptions:read'])
            ->get(route('billing.subscriptions'))
            ->assertOk();
    }

    public function test_the_map_gates_the_sensitive_routes(): void
    {
        $expected = [
            'billing.catalog.prices.store' => 'billing.permission:catalog:manage',
            'billing.catalog.plans.retire' => 'billing.permission:catalog:manage',
            'billing.subscriptions.cancel' => 'billing.permission:subscriptions:manage',
            'billing.invoices.void' => 'billing.permission:invoices:manage',
            'billing.invoices.mark-paid' => 'billing.permission:invoices:manage',
            'billing.invoices.refund' => 'billing.permission:invoices:refund',
            'billing.customers.wallet.adjust' => 'billing.permission:wallet:manage',
            'billing.licenses.issue' => 'billing.permission:licenses:issue',
            'billing.licenses.revoke' => 'billing.permission:licenses:revoke',
            'analytics.revenue' => 'billing.permission:analytics:read',
            'billing.settings' => 'billing.permission:settings:read',
        ];

        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} is missing.");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Route {$name} should be gated by {$middleware}.");
        }
    }

    public function test_the_invoice_manage_and_refund_slugs_are_distinct_under_enforcement(): void
    {
        config()->set('billing.rbac.enforce', true);

        $invoice = Invoice::query()->firstOrFail();

        // A refund-only holder may NOT void (a lifecycle action carrying `invoices:manage`).
        $this->signedInWith(['invoices:refund'])
            ->post(route('billing.invoices.void', $invoice->id))
            ->assertStatus(403);

        // The manage holder clears the gate (the action itself may still 302/redirect).
        $this->signedInWith(['invoices:manage'])
            ->post(route('billing.invoices.void', $invoice->id))
            ->assertStatus(302);
    }

    public function test_wallet_manage_gates_the_adjustment_route(): void
    {
        config()->set('billing.rbac.enforce', true);

        // `customers:manage` no longer authorizes a wallet adjustment (Wave 4 split).
        $this->signedInWith(['customers:manage'])
            ->post(route('billing.customers.wallet.adjust', 'org_hverdag'))
            ->assertStatus(403);
    }

    /**
     * A signed-in Cbox ID session carrying the given permission slugs.
     *
     * @param  list<string>  $permissions
     */
    private function signedInWith(array $permissions): self
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|operator',
            'name' => 'Test Operator',
            'email' => 'ops@example.test',
            'org' => 'org_hverdag',
            'picture' => null,
            'permissions' => $permissions,
        ]]);

        return $this;
    }
}
