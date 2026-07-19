<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Products console CRUD: create persists + lists, edit updates, and delete is guarded —
 * a product that still groups plans is archived (soft), never hard-deleted, so catalog
 * history is never orphaned; only an empty product deletes outright.
 */
class CatalogProductConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_index_renders_with_search(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->get('/products')
            ->assertOk()
            ->assertSee('Cbox Billing');

        $this->withSession($this->session)->get('/products?q=zzz-none')
            ->assertOk()
            ->assertSee('No matches');
    }

    public function test_create_persists_and_appears_in_the_list(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->post('/products', [
            'key' => 'observability',
            'name' => 'Observability',
            'description' => 'Logs and traces.',
        ])->assertRedirect();

        $product = Product::query()->where('key', 'observability')->firstOrFail();
        $this->assertSame('Observability', $product->name);

        $this->withSession($this->session)->get('/products')->assertOk()->assertSee('Observability');
    }

    public function test_a_duplicate_key_is_refused(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->post('/products', [
            'key' => 'cbox-billing', // already seeded
            'name' => 'Dupe',
            'description' => null,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, Product::query()->where('key', 'cbox-billing')->count());
    }

    public function test_edit_updates(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();

        $this->withSession($this->session)->put('/products/'.$product->id, [
            'key' => 'cbox-billing',
            'name' => 'Cbox Billing Platform',
            'description' => 'Updated.',
        ])->assertRedirect('/products/'.$product->id);

        $this->assertSame('Cbox Billing Platform', $product->fresh()?->name);
    }

    public function test_delete_is_guarded_when_the_product_has_plans(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();

        $this->withSession($this->session)->delete('/products/'.$product->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        // The guard held — the referenced product is still there.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_archive_and_delete_of_an_empty_product(): void
    {
        $this->seed(CatalogSeeder::class);

        $product = Product::query()->create(['key' => 'draft', 'name' => 'Draft']);

        // Archive is a soft-deactivate.
        $this->withSession($this->session)->post('/products/'.$product->id.'/archive')->assertRedirect();
        $this->assertNotNull($product->fresh()?->archived_at);

        // An empty product hard-deletes.
        $this->withSession($this->session)->delete('/products/'.$product->id)
            ->assertRedirect('/products')
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_the_detail_page_renders_plans_and_stats(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();

        $this->withSession($this->session)->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Serving subscribers')
            ->assertSee('Team')
            ->assertSee('Starter');
    }
}
