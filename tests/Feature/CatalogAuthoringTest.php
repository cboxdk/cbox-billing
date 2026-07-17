<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanPrice;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog pricing-authoring flow: an operator creates/edits a plan price in the
 * console, choosing the pricing model and (for the tiered models) the tier table. The
 * server-side validation mirrors the engine's TierCalculator rules so an authored price
 * always prices, and a malformed tier set is refused before it is ever persisted.
 */
class CatalogAuthoringTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_new_price_form_renders(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->get('/catalog/prices/new')
            ->assertOk()
            ->assertSee('New price')
            ->assertSee('Pricing model')
            ->assertSee('graduated');
    }

    public function test_a_graduated_price_persists_renders_and_prices_a_quantity(): void
    {
        $this->seed(CatalogSeeder::class);
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        // Author a graduated GBP price: first 10 seats free, 11–50 at 99,00, then 79,00.
        $response = $this->withSession($this->session)->post('/catalog/prices', [
            'plan_id' => $starter->id,
            'currency' => 'GBP',
            'pricing_model' => 'graduated',
            'price_minor' => 0,
            'tiers' => [
                ['up_to' => 10, 'unit_minor' => 0, 'flat_minor' => ''],
                ['up_to' => 50, 'unit_minor' => 9_900, 'flat_minor' => ''],
                ['up_to' => '', 'unit_minor' => 7_900, 'flat_minor' => ''],
            ],
        ]);

        $response->assertRedirect('/catalog');
        $response->assertSessionHas('catalog_notice');

        // It persisted as a real graduated price with its three ordered tiers…
        $price = PlanPrice::query()->where('plan_id', $starter->id)->where('currency', 'GBP')->firstOrFail();
        $this->assertSame('graduated', $price->pricing_model);
        $this->assertSame(3, $price->tiers()->count());

        // …and prices a sample quantity through the SAME engine calculator that bills it:
        // 20 seats graduated = 10×0 + 10×9.900 = 99.000 minor.
        $this->assertSame(99_000, $price->load('tiers')->toPrice()->amountFor(20)->minor());

        // …and it renders on the catalog screen's tier table.
        $this->withSession($this->session)->get('/catalog')
            ->assertOk()
            ->assertSee('graduated')
            ->assertSee('GBP 99,00');
    }

    public function test_a_package_price_requires_a_size_and_block_price_and_prices_blocks(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/prices', [
            'plan_id' => $team->id,
            'currency' => 'GBP',
            'pricing_model' => 'package',
            'price_minor' => 0,
            'package_size' => 10,
            'tiers' => [
                ['up_to' => '', 'unit_minor' => 0, 'flat_minor' => 50_000],
            ],
        ])->assertRedirect('/catalog')->assertSessionHas('catalog_notice');

        $price = PlanPrice::query()->where('plan_id', $team->id)->where('currency', 'GBP')->firstOrFail();
        $this->assertSame('package', $price->pricing_model);
        $this->assertSame(10, $price->package_size);

        // 25 seats, packs of 10 = ceil(25/10) = 3 blocks × 50.000 = 150.000 minor.
        $this->assertSame(150_000, $price->load('tiers')->toPrice()->amountFor(25)->minor());
    }

    public function test_a_non_ascending_tier_set_is_rejected(): void
    {
        $this->seed(CatalogSeeder::class);
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/prices', [
            'plan_id' => $starter->id,
            'currency' => 'GBP',
            'pricing_model' => 'graduated',
            'price_minor' => 0,
            'tiers' => [
                ['up_to' => 50, 'unit_minor' => 9_900, 'flat_minor' => ''],
                ['up_to' => 10, 'unit_minor' => 7_900, 'flat_minor' => ''], // bound goes backwards
                ['up_to' => '', 'unit_minor' => 5_000, 'flat_minor' => ''],
            ],
        ])->assertRedirect()->assertSessionHas('catalog_error');

        // Nothing was persisted — a malformed tier set never reaches the catalog.
        $this->assertFalse(PlanPrice::query()->where('plan_id', $starter->id)->where('currency', 'GBP')->exists());
    }

    public function test_a_tier_set_without_a_final_unbounded_tier_is_rejected(): void
    {
        $this->seed(CatalogSeeder::class);
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/prices', [
            'plan_id' => $starter->id,
            'currency' => 'GBP',
            'pricing_model' => 'graduated',
            'price_minor' => 0,
            'tiers' => [
                ['up_to' => 10, 'unit_minor' => 0, 'flat_minor' => ''],
                ['up_to' => 50, 'unit_minor' => 9_900, 'flat_minor' => ''], // last tier still bounded
            ],
        ])->assertRedirect()->assertSessionHas('catalog_error');

        $this->assertFalse(PlanPrice::query()->where('plan_id', $starter->id)->where('currency', 'GBP')->exists());
    }
}
