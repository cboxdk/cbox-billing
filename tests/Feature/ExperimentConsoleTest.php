<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\OperatorAuditEvent;
use App\Models\Plan;
use App\Models\PricingTable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The experiments console: CRUD persists the experiment + its variants, the lifecycle
 * (start / conclude+promote) drives what the public page serves, both gate on permission, and
 * every mutation is audit-logged by the central recording seam.
 */
class ExperimentConsoleTest extends TestCase
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

    private function baseTable(): PricingTable
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $table = PricingTable::query()->create(['key' => 'plans', 'name' => 'Plans', 'default_currency' => 'EUR', 'active' => true]);
        $table->columns()->create(['plan_id' => $team->id, 'sort_order' => 0]);

        return $table;
    }

    public function test_index_lists_experiments(): void
    {
        $base = $this->baseTable();
        Experiment::query()->create([
            'key' => 'annual', 'name' => 'Annual test', 'status' => ExperimentStatus::Draft->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value, 'pricing_table_id' => $base->id,
        ]);

        $this->withSession($this->session)->get('/experiments')
            ->assertOk()
            ->assertSee('Annual test')
            ->assertSee('annual');
    }

    public function test_create_persists_the_experiment_with_control_and_challenger(): void
    {
        $base = $this->baseTable();
        $challenger = PricingTable::query()->create(['key' => 'plans-b', 'name' => 'Plans B', 'active' => false]);

        $this->withSession($this->session)->post('/experiments', [
            'key' => 'annual-first',
            'name' => 'Annual-first layout',
            'hypothesis' => 'An annual-first layout lifts completion.',
            'primary_metric' => 'checkout_completed',
            'pricing_table_id' => (string) $base->id,
            'control' => '0',
            'variants' => [
                ['label' => 'Control', 'weight' => '1', 'served_pricing_table_id' => ''],
                ['label' => 'Annual-first', 'weight' => '3', 'served_pricing_table_id' => (string) $challenger->id],
            ],
        ])->assertRedirect();

        $experiment = Experiment::query()->where('key', 'annual-first')->firstOrFail();
        $this->assertSame(ExperimentStatus::Draft, $experiment->status);
        $this->assertSame(2, $experiment->variants()->count());

        $control = $experiment->control();
        $this->assertNotNull($control);
        $this->assertSame('Control', $control->label);
        $this->assertNull($control->served_pricing_table_id);

        $challengerVariant = $experiment->variants()->where('is_control', false)->firstOrFail();
        $this->assertSame(3, $challengerVariant->weight);
        $this->assertSame($challenger->id, $challengerVariant->served_pricing_table_id);
    }

    public function test_create_requires_exactly_one_control(): void
    {
        $base = $this->baseTable();

        // Two rows, but `control` points at neither's index → no control selected.
        $this->withSession($this->session)->post('/experiments', [
            'key' => 'no-control', 'name' => 'No control', 'primary_metric' => 'checkout_completed',
            'pricing_table_id' => (string) $base->id, 'control' => '99',
            'variants' => [
                ['label' => 'A', 'weight' => '1'],
                ['label' => 'B', 'weight' => '1'],
            ],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Experiment::query()->count());
    }

    public function test_start_transitions_to_running_and_is_audit_logged(): void
    {
        $experiment = $this->draftExperiment();

        $this->withSession($this->session)->post('/experiments/'.$experiment->id.'/start')
            ->assertRedirect();

        $this->assertSame(ExperimentStatus::Running, $experiment->fresh()->status);
        $this->assertNotNull($experiment->fresh()->started_at);

        $this->assertSame(1, OperatorAuditEvent::query()->where('action', AuditAction::ExperimentStarted->value)->count());
    }

    public function test_conclude_promotes_the_winner_and_is_audit_logged(): void
    {
        $experiment = $this->draftExperiment();
        $experiment->forceFill(['status' => ExperimentStatus::Running->value, 'started_at' => now()])->save();
        $winner = $experiment->variants()->where('is_control', false)->firstOrFail();

        $this->withSession($this->session)->post('/experiments/'.$experiment->id.'/conclude', [
            'winner' => (string) $winner->id,
        ])->assertRedirect();

        $experiment->refresh();
        $this->assertSame(ExperimentStatus::Concluded, $experiment->status);
        $this->assertSame($winner->id, $experiment->promoted_variant_id);

        $this->assertSame(1, OperatorAuditEvent::query()->where('action', AuditAction::ExperimentConcluded->value)->count());
    }

    public function test_create_and_edit_forms_render(): void
    {
        $this->baseTable();

        $this->withSession($this->session)->get('/experiments/new')
            ->assertOk()
            ->assertSee('New experiment')
            ->assertSee('Runs on (pricing table)');

        $experiment = $this->draftExperiment();
        $this->withSession($this->session)->get('/experiments/'.$experiment->id.'/edit')
            ->assertOk()
            ->assertSee('Edit experiment')
            ->assertSee('Challenger');
    }

    public function test_show_renders_the_results_dashboard(): void
    {
        $experiment = $this->draftExperiment();

        $this->withSession($this->session)->get('/experiments/'.$experiment->id)
            ->assertOk()
            ->assertSee('Conversion rate by variant')
            ->assertSee('two-proportion z-test', false); // the honest caveat is present
    }

    public function test_delete_removes_the_experiment(): void
    {
        $experiment = $this->draftExperiment();

        $this->withSession($this->session)->delete('/experiments/'.$experiment->id)->assertRedirect();

        $this->assertDatabaseMissing('experiments', ['id' => $experiment->id]);
    }

    public function test_writes_require_the_catalog_manage_permission(): void
    {
        config()->set('billing.rbac.enforce', true);
        $base = $this->baseTable();
        $experiment = $this->draftExperiment();

        $readOnly = ['auth.user' => [
            'sub' => 'demo|op', 'name' => 'Op', 'email' => 'op@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => ['analytics:read'],
        ]];

        // analytics:read reaches the list + detail…
        $this->withSession($readOnly)->get('/experiments')->assertOk();
        $this->withSession($readOnly)->get('/experiments/'.$experiment->id)->assertOk();

        // …but NOT the create form, store, start, conclude or delete (all catalog:manage).
        $this->withSession($readOnly)->get('/experiments/new')->assertStatus(403);
        $this->withSession($readOnly)->post('/experiments', ['key' => 'x', 'name' => 'X'])->assertStatus(403);
        $this->withSession($readOnly)->post('/experiments/'.$experiment->id.'/start')->assertStatus(403);
        $this->withSession($readOnly)->post('/experiments/'.$experiment->id.'/conclude')->assertStatus(403);
        $this->withSession($readOnly)->delete('/experiments/'.$experiment->id)->assertStatus(403);

        // A catalog:manage holder can start it.
        $manage = $readOnly;
        $manage['auth.user']['permissions'] = ['analytics:read', 'catalog:manage'];
        $this->withSession($manage)->post('/experiments/'.$experiment->id.'/start')->assertRedirect();
    }

    public function test_reads_require_the_analytics_read_permission(): void
    {
        config()->set('billing.rbac.enforce', true);
        $experiment = $this->draftExperiment();

        $noPerms = ['auth.user' => [
            'sub' => 'demo|op', 'name' => 'Op', 'email' => 'op@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => ['invoices:read'],
        ]];

        $this->withSession($noPerms)->get('/experiments')->assertStatus(403);
        $this->withSession($noPerms)->get('/experiments/'.$experiment->id)->assertStatus(403);
    }

    private function draftExperiment(): Experiment
    {
        $base = PricingTable::query()->firstOr(fn () => $this->baseTable());
        $challenger = PricingTable::query()->create(['key' => 'chal-'.uniqid(), 'name' => 'Challenger', 'active' => false]);

        $experiment = Experiment::query()->create([
            'key' => 'exp-'.uniqid(), 'name' => 'Experiment', 'status' => ExperimentStatus::Draft->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value, 'pricing_table_id' => $base->id,
        ]);
        $experiment->variants()->create(['label' => 'Control', 'is_control' => true, 'weight' => 1, 'sort_order' => 0]);
        $experiment->variants()->create(['label' => 'Challenger', 'is_control' => false, 'weight' => 1, 'sort_order' => 1, 'served_pricing_table_id' => $challenger->id]);

        return $experiment->load('variants');
    }
}
