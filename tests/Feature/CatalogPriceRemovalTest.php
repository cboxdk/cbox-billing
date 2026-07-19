<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Removing a plan price version is guarded by the currency-lock invariant: the effective
 * price a serving subscriber is grandfathered onto (their org's billing currency) cannot be
 * pulled out from under them, while an unused currency's price removes cleanly.
 */
class CatalogPriceRemovalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_removing_the_price_a_serving_subscriber_bills_on_is_refused(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // An org billed in DKK, serving on Team.
        Organization::query()->create(['id' => 'org_dkk', 'name' => 'DKK Co', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
        Subscription::query()->create([
            'organization_id' => 'org_dkk', 'plan_id' => $team->id, 'status' => SubscriptionStatus::Active, 'seats' => 1,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'), 'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        $dkk = PlanPrice::query()->where('plan_id', $team->id)->where('currency', 'DKK')->firstOrFail();

        $this->withSession($this->session)->delete('/catalog/prices/'.$dkk->id)
            ->assertRedirect()
            ->assertSessionHas('catalog_error');

        $this->assertDatabaseHas('plan_prices', ['id' => $dkk->id]);
    }

    public function test_removing_an_unused_currency_price_succeeds(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // Nobody bills in EUR, so its price version removes cleanly (with its tiers).
        $eur = PlanPrice::query()->where('plan_id', $team->id)->where('currency', 'EUR')->firstOrFail();

        $this->withSession($this->session)->delete('/catalog/prices/'.$eur->id)
            ->assertRedirect('/catalog')
            ->assertSessionHas('catalog_notice');

        $this->assertDatabaseMissing('plan_prices', ['id' => $eur->id]);
        $this->assertDatabaseMissing('plan_price_tiers', ['plan_price_id' => $eur->id]);
    }
}
