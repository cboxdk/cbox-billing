<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plan entitlement and credit-grant editors reachable from the plan detail page —
 * create / edit / delete, validated against the meters that exist.
 */
class CatalogPlanEditorsConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    public function test_create_edit_and_delete_an_entitlement(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $meter = Meter::query()->create(['key' => 'jobs.run', 'name' => 'Jobs', 'unit' => 'jobs', 'aggregation' => Aggregation::Count]);

        // Create.
        $this->withSession($this->session)->post('/catalog/plans/'.$team->id.'/entitlements', [
            'meter_id' => $meter->id,
            'enabled' => '1',
            'unlimited' => '0',
            'allowance' => 5000,
            'multiplier' => 0.01,
            'overage' => 'bill',
        ])->assertRedirect('/catalog/plans/'.$team->id)->assertSessionHas('status');

        $entitlement = PlanEntitlement::query()->where('plan_id', $team->id)->where('meter_id', $meter->id)->firstOrFail();
        $this->assertSame(5000, $entitlement->allowance);
        $this->assertSame(OverageBehaviour::Bill, $entitlement->overage);

        // Edit.
        $this->withSession($this->session)->put('/catalog/plans/'.$team->id.'/entitlements/'.$entitlement->id, [
            'meter_id' => $meter->id,
            'enabled' => '1',
            'unlimited' => '1',
            'allowance' => 0,
            'multiplier' => '',
            'overage' => 'block',
        ])->assertRedirect('/catalog/plans/'.$team->id);

        $entitlement->refresh();
        $this->assertTrue($entitlement->unlimited);

        // Delete.
        $this->withSession($this->session)->delete('/catalog/plans/'.$team->id.'/entitlements/'.$entitlement->id)
            ->assertRedirect('/catalog/plans/'.$team->id);
        $this->assertDatabaseMissing('plan_entitlements', ['id' => $entitlement->id]);
    }

    public function test_a_duplicate_meter_entitlement_is_refused(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        // Team already has an entitlement for the seeded api.requests meter.
        $meter = Meter::query()->where('key', 'api.requests')->firstOrFail();

        $this->withSession($this->session)->post('/catalog/plans/'.$team->id.'/entitlements', [
            'meter_id' => $meter->id,
            'enabled' => '1',
            'unlimited' => '0',
            'allowance' => 10,
            'multiplier' => '',
            'overage' => 'block',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, PlanEntitlement::query()->where('plan_id', $team->id)->where('meter_id', $meter->id)->count());
    }

    public function test_create_edit_and_delete_a_credit_grant(): void
    {
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // Create a one-time promotional grant.
        $this->withSession($this->session)->post('/catalog/plans/'.$team->id.'/credit-grants', [
            'pool' => 'promotional',
            'kind' => 'base',
            'cadence' => 'once',
            'amount' => 100000,
            'amount_mode' => 'fixed',
            'rollover_seconds' => '',
            'denomination' => 'credit',
        ])->assertRedirect('/catalog/plans/'.$team->id)->assertSessionHas('status');

        $grant = PlanCreditGrant::query()->where('plan_id', $team->id)->where('pool', 'promotional')->firstOrFail();
        $this->assertSame(100000, $grant->amount);

        // Edit.
        $this->withSession($this->session)->put('/catalog/plans/'.$team->id.'/credit-grants/'.$grant->id, [
            'pool' => 'promotional',
            'kind' => 'base',
            'cadence' => 'monthly',
            'amount' => 250000,
            'amount_mode' => 'distributed',
            'rollover_seconds' => 3600,
            'denomination' => 'credit',
        ])->assertRedirect('/catalog/plans/'.$team->id);

        $grant->refresh();
        $this->assertSame(250000, $grant->amount);
        $this->assertSame(3600, $grant->rollover_seconds);

        // Delete.
        $this->withSession($this->session)->delete('/catalog/plans/'.$team->id.'/credit-grants/'.$grant->id)
            ->assertRedirect('/catalog/plans/'.$team->id);
        $this->assertDatabaseMissing('plan_credit_grants', ['id' => $grant->id]);
    }
}
