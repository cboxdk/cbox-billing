<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Meters console CRUD. A meter's aggregation is the engine {@see Aggregation} its usage
 * is billed with — it round-trips through persistence AND into the resolved metering policy.
 * Delete is guarded: a referenced meter is archived, never hard-deleted.
 */
class CatalogMeterConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_the_index_renders(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->get('/meters')
            ->assertOk()
            ->assertSee('API requests');
    }

    public function test_create_persists_and_the_aggregation_round_trips(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->withSession($this->session)->post('/meters', [
            'key' => 'peak.seats',
            'name' => 'Peak seats',
            'unit' => 'seats',
            'aggregation' => 'max',
            'display' => 'Peak concurrent seats',
        ])->assertRedirect()->assertSessionHas('status');

        $meter = Meter::query()->where('key', 'peak.seats')->firstOrFail();
        $this->assertSame(Aggregation::Max, $meter->aggregation);
        $this->assertSame('Peak concurrent seats', $meter->display);

        // The detail page shows the persisted aggregation.
        $this->withSession($this->session)->get('/meters/'.$meter->id)
            ->assertOk()
            ->assertSee('max')
            ->assertSee('Peak concurrent seats');
    }

    public function test_edit_updates_the_aggregation(): void
    {
        $this->seed(CatalogSeeder::class);
        $meter = Meter::query()->where('key', 'api.requests')->firstOrFail();

        $this->withSession($this->session)->put('/meters/'.$meter->id, [
            'key' => 'api.requests',
            'name' => 'API requests',
            'unit' => 'requests',
            'aggregation' => 'weighted_sum',
            'display' => null,
        ])->assertRedirect('/meters/'.$meter->id);

        $this->assertSame(Aggregation::WeightedSum, $meter->fresh()?->aggregation);
    }

    public function test_the_meter_aggregation_flows_into_the_engine_metering_policy(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $meter = Meter::query()->create(['key' => 'peak', 'name' => 'Peak', 'unit' => 'x', 'aggregation' => Aggregation::Max]);

        $entitlement = PlanEntitlement::query()->create([
            'plan_id' => $team->id, 'meter_id' => $meter->id, 'enabled' => true,
            'allowance' => 10, 'multiplier' => null, 'unlimited' => false, 'overage' => OverageBehaviour::Block,
        ]);

        // The projected engine policy carries the meter's authored aggregation.
        $this->assertSame(Aggregation::Max, $entitlement->fresh()?->toMeterPolicy()->aggregation);
    }

    public function test_delete_is_guarded_when_the_meter_is_referenced(): void
    {
        $this->seed(CatalogSeeder::class);
        $meter = Meter::query()->where('key', 'api.requests')->firstOrFail(); // referenced by seeded entitlements

        $this->withSession($this->session)->delete('/meters/'.$meter->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('meters', ['id' => $meter->id]);

        // Archive is the offered path instead.
        $this->withSession($this->session)->post('/meters/'.$meter->id.'/archive')->assertRedirect();
        $this->assertNotNull($meter->fresh()?->archived_at);
    }

    public function test_delete_succeeds_for_an_unreferenced_meter(): void
    {
        $this->seed(CatalogSeeder::class);
        $meter = Meter::query()->create(['key' => 'orphan', 'name' => 'Orphan', 'unit' => 'x', 'aggregation' => Aggregation::Count]);

        $this->withSession($this->session)->delete('/meters/'.$meter->id)
            ->assertRedirect('/meters')
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('meters', ['id' => $meter->id]);
    }
}
