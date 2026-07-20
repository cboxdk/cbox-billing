<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PricingTable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing-tables console (#57): CRUD persists the table + its plan columns and feature rows,
 * the detail page renders the live preview + the embed snippet (carrying the public URL), and the
 * writes are gated behind `catalog:manage`.
 */
class PricingTableConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, string|null>> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test',
        'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_index_lists_tables_for_an_operator(): void
    {
        PricingTable::query()->create(['key' => 'plans', 'name' => 'Plans & pricing', 'active' => true]);

        $this->withSession($this->session)->get('/pricing-tables')
            ->assertOk()
            ->assertSee('Plans &amp; pricing', false)
            ->assertSee('/pricing/plans');
    }

    public function test_create_persists_the_table_with_columns_and_feature_rows(): void
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $sso = Feature::query()->where('key', 'sso')->firstOrFail();

        $this->withSession($this->session)->post('/pricing-tables', [
            'key' => 'my-table',
            'name' => 'My table',
            'default_currency' => 'EUR',
            'currencies' => ['EUR'],
            'interval_toggle' => '1',
            'active' => '1',
            'cta_label' => 'Buy now',
            'columns' => [
                ['plan_id' => (string) $team->id, 'featured' => '1', 'badge' => 'Popular'],
                ['plan_id' => '', 'badge' => ''], // empty template row, skipped
            ],
            'features' => [(string) $sso->id],
        ])->assertRedirect();

        $table = PricingTable::query()->where('key', 'my-table')->firstOrFail();
        $this->assertSame('My table', $table->name);
        $this->assertSame(['EUR'], $table->currencies);
        $this->assertTrue($table->active);
        $this->assertSame(1, $table->columns()->count());
        $this->assertSame(1, $table->featureRows()->count());

        $column = $table->columns()->firstOrFail();
        $this->assertSame($team->id, $column->plan_id);
        $this->assertTrue($column->featured);
        $this->assertSame('Popular', $column->badge);
    }

    public function test_show_renders_live_preview_and_embed_snippet_with_public_url(): void
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $table = PricingTable::query()->create(['key' => 'demo', 'name' => 'Demo', 'default_currency' => 'EUR', 'active' => true]);
        $table->columns()->create(['plan_id' => $team->id, 'sort_order' => 0]);

        $this->withSession($this->session)->get('/pricing-tables/'.$table->id)
            ->assertOk()
            ->assertSee('Embed on your site')
            ->assertSee('/pricing/demo/embed', false)          // the embed snippet URL
            ->assertSee(route('billing.pricing-tables.preview', $table->id), false); // the live-preview iframe
    }

    public function test_preview_renders_the_actual_table(): void
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $table = PricingTable::query()->create(['key' => 'demo', 'name' => 'Demo', 'default_currency' => 'EUR', 'active' => false]);
        $table->columns()->create(['plan_id' => $team->id, 'sort_order' => 0]);

        // Preview renders even though the table is offline (it is the console's own route).
        $this->withSession($this->session)->get('/pricing-tables/'.$table->id.'/preview')
            ->assertOk()
            ->assertSee('Team')
            ->assertSee('EUR 169,00');
    }

    public function test_update_and_delete(): void
    {
        $table = PricingTable::query()->create(['key' => 'demo', 'name' => 'Demo', 'active' => true]);

        $this->withSession($this->session)->put('/pricing-tables/'.$table->id, [
            'key' => 'demo', 'name' => 'Renamed', 'active' => '1',
        ])->assertRedirect();
        $this->assertSame('Renamed', $table->fresh()?->name);

        $this->withSession($this->session)->delete('/pricing-tables/'.$table->id)->assertRedirect();
        $this->assertDatabaseMissing('pricing_tables', ['id' => $table->id]);
    }

    public function test_duplicate_key_is_refused(): void
    {
        PricingTable::query()->create(['key' => 'taken', 'name' => 'Taken', 'active' => true]);

        $this->withSession($this->session)->post('/pricing-tables', ['key' => 'taken', 'name' => 'Another'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, PricingTable::query()->where('key', 'taken')->count());
    }

    public function test_writes_require_the_catalog_manage_permission(): void
    {
        config()->set('billing.rbac.enforce', true);

        $signed = ['auth.user' => [
            'sub' => 'demo|op', 'name' => 'Op', 'email' => 'op@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => ['catalog:read'],
        ]];

        // A holder of only catalog:read cannot reach the create form or store.
        $this->withSession($signed)->get('/pricing-tables/new')->assertStatus(403);
        $this->withSession($signed)->post('/pricing-tables', ['key' => 'x', 'name' => 'X'])->assertStatus(403);

        // Granting catalog:manage clears the gate.
        $signed['auth.user']['permissions'] = ['catalog:manage'];
        $this->withSession($signed)->get('/pricing-tables/new')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/pricing-tables')->assertRedirect('/login');
    }
}
