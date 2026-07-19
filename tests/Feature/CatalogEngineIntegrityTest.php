<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Catalog\PlanCatalog;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog CRUD must not regress the engine-facing catalog: the same {@see PlanCatalog}
 * the pricing/subscription path resolves through keeps pricing every plan after a metadata
 * edit (grandfathering intact), and the write routes stay gated by the RBAC middleware.
 */
class CatalogEngineIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_engine_catalog_still_resolves_and_prices_after_a_plan_metadata_edit(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $at = new DateTimeImmutable('2026-07-18');
        $before = PlanCatalog::for('DKK')->priceFor('team', $at);
        $this->assertNotNull($before);
        $quantityBefore = PlanCatalog::for('DKK')->priceQuantity('team', 20, $at);
        $this->assertNotNull($quantityBefore);

        // A metadata-only edit through the console.
        $this->withSession($this->session)->put('/catalog/plans/'.$team->id, [
            'product_id' => $team->product_id,
            'key' => 'team',
            'name' => 'Team Plus',
            'interval' => 'month',
            'active' => '1',
        ])->assertRedirect();

        // The engine catalog still resolves the same currency price and prices the same qty.
        $after = PlanCatalog::for('DKK')->priceFor('team', $at);
        $this->assertNotNull($after);
        $this->assertSame($before->unitAmount->minor(), $after->unitAmount->minor());
        $this->assertSame(
            $quantityBefore->minor(),
            PlanCatalog::for('DKK')->priceQuantity('team', 20, $at)?->minor(),
        );
    }

    public function test_write_routes_are_gated_by_the_permission_middleware(): void
    {
        $this->seed(CatalogSeeder::class);
        config()->set('billing.rbac.enforce', true);

        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();

        // A non-holder is refused every catalog write surface.
        $this->signedInWith(['subscriptions:read'])->get('/products/new')->assertStatus(403);
        $this->signedInWith(['subscriptions:read'])->get('/meters/new')->assertStatus(403);
        $this->signedInWith(['subscriptions:read'])->post('/products', [
            'key' => 'nope', 'name' => 'Nope', 'description' => null,
        ])->assertStatus(403);

        // A catalog:manage holder gets through.
        $this->signedInWith(['catalog:manage'])->get('/products/new')->assertOk();
        $this->signedInWith(['catalog:read'])->get('/products/'.$product->id)->assertOk();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signedInWith(array $permissions): self
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|operator', 'name' => 'Test Operator', 'email' => 'ops@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => $permissions,
        ]]);

        return $this;
    }
}
