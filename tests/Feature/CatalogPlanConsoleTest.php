<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The per-plan detail hub + plan CRUD. The detail page renders prices, entitlements, credit
 * grants and the subscriber count; editing a plan is metadata-only (never reprices existing
 * subscribers); and delete is archive-when-subscribed, hard-delete only when nobody is on it.
 */
class CatalogPlanConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_detail_page_renders_prices_entitlements_credit_grants_and_subscriber_count(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $this->subscribe('org_detail', $team);

        $this->withSession($this->session)->get('/catalog/plans/'.$team->id)
            ->assertOk()
            ->assertSee('Team')
            ->assertSee('Prices')
            ->assertSee('Entitlements')
            ->assertSee('Credit grants')
            ->assertSee('API requests')       // an entitlement meter
            ->assertSee('Serving subscribers')
            ->assertSee('included');          // the seeded credit-grant pool
    }

    public function test_create_persists(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/plans', [
            'product_id' => $product->id,
            'key' => 'enterprise',
            'name' => 'Enterprise',
            'interval' => 'month',
            'active' => '1',
        ])->assertRedirect();

        $plan = Plan::query()->where('key', 'enterprise')->firstOrFail();
        $this->assertTrue($plan->active);
        $this->assertSame($product->id, $plan->product_id);
    }

    public function test_edit_is_metadata_only_and_does_not_reprice(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $before = PlanPrice::query()->where('plan_id', $team->id)->pluck('price_minor', 'currency')->all();

        $this->withSession($this->session)->put('/catalog/plans/'.$team->id, [
            'product_id' => $team->product_id,
            'key' => 'team',
            'name' => 'Team (renamed)',
            'interval' => 'month',
            'active' => '1',
        ])->assertRedirect('/catalog/plans/'.$team->id);

        $this->assertSame('Team (renamed)', $team->fresh()?->name);

        // Grandfathering: not one price row changed — plan edits never touch the money.
        $after = PlanPrice::query()->where('plan_id', $team->id)->pluck('price_minor', 'currency')->all();
        $this->assertSame($before, $after);
    }

    public function test_archive_makes_the_plan_legacy(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/plans/'.$team->id.'/archive')->assertRedirect();

        $this->assertFalse($team->fresh()?->active);
    }

    public function test_delete_is_guarded_when_the_plan_has_subscribers(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $this->subscribe('org_locked', $team);

        $this->withSession($this->session)->delete('/catalog/plans/'.$team->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('plans', ['id' => $team->id]);
    }

    public function test_delete_succeeds_when_no_subscribers(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::query()->where('key', 'cbox-billing')->firstOrFail();
        $plan = Plan::query()->create([
            'product_id' => $product->id, 'key' => 'scratch', 'name' => 'Scratch', 'interval' => 'month', 'active' => true,
        ]);

        $this->withSession($this->session)->delete('/catalog/plans/'.$plan->id)
            ->assertRedirect('/catalog')
            ->assertSessionHas('catalog_notice');

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    private function subscribe(string $org, Plan $plan): void
    {
        Organization::query()->create(['id' => $org, 'name' => $org, 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);
    }
}
