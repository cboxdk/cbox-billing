<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\StorefrontExperimentResolver;
use App\Models\Experiment;
use App\Models\ExperimentImpression;
use App\Models\Plan;
use App\Models\PricingTable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public `/pricing/{key}` under an A/B experiment: a running experiment serves the assigned
 * variant's table (an impression recorded once per visitor); a draft or concluded-without-winner
 * experiment serves the plain base table; a concluded experiment with a promoted winner serves the
 * winner's table permanently.
 *
 * Assignment is controlled by loading all the weight onto one arm (a zero-weight arm is never
 * bucketed), so a request deterministically serves a known variant without needing to control the
 * anonymous cookie.
 */
class ExperimentStorefrontServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    /** A base table (Team column) + a challenger table (Business column) — the plan name distinguishes them. */
    private function tables(): array
    {
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $business = Plan::query()->where('key', 'business')->firstOrFail();

        $base = PricingTable::query()->create(['key' => 'plans', 'name' => 'Plans', 'default_currency' => 'EUR', 'active' => true]);
        $base->columns()->create(['plan_id' => $team->id, 'sort_order' => 0]);

        $challenger = PricingTable::query()->create(['key' => 'plans-b', 'name' => 'Plans B', 'default_currency' => 'EUR', 'active' => false]);
        $challenger->columns()->create(['plan_id' => $business->id, 'sort_order' => 0]);

        return [$base, $challenger];
    }

    private function experiment(PricingTable $base, PricingTable $challenger, ExperimentStatus $status, int $controlWeight, int $challengerWeight): Experiment
    {
        $experiment = Experiment::query()->create([
            'key' => 'layout-test',
            'name' => 'Layout test',
            'status' => $status->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value,
            'pricing_table_id' => $base->id,
            'started_at' => $status === ExperimentStatus::Draft ? null : now(),
        ]);

        $experiment->variants()->create(['label' => 'Control', 'is_control' => true, 'weight' => $controlWeight, 'sort_order' => 0, 'served_pricing_table_id' => null]);
        $experiment->variants()->create(['label' => 'Challenger', 'is_control' => false, 'weight' => $challengerWeight, 'sort_order' => 1, 'served_pricing_table_id' => $challenger->id]);

        return $experiment->load('variants');
    }

    public function test_a_running_experiment_serves_the_assigned_variants_table_and_records_an_impression(): void
    {
        [$base, $challenger] = $this->tables();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Running, 0, 1); // all traffic → challenger

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        $this->assertStringContainsString('Business', $html);
        $this->assertStringNotContainsString('>Team<', $html);

        // Exactly one impression, on the challenger variant.
        $this->assertSame(1, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());
        $challengerVariant = $experiment->variants->firstWhere('is_control', false);
        $this->assertSame(1, ExperimentImpression::query()->where('experiment_variant_id', $challengerVariant->id)->count());
    }

    public function test_the_served_variants_cta_links_carry_the_attribution_triple(): void
    {
        [$base, $challenger] = $this->tables();
        // Give the challenger a placeholder CTA template so attribution rides along as a query.
        $challenger->forceFill(['cta_url_template' => 'https://shop.test/buy?plan={plan}'])->save();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Running, 0, 1);
        $challengerVariant = $experiment->variants->firstWhere('is_control', false);

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        // The CTA carries the experiment key, the assigned variant id and the anonymous visitor id.
        $this->assertStringContainsString('cbox_exp=layout-test', $html);
        $this->assertStringContainsString('cbox_var='.$challengerVariant->id, $html);
        $this->assertStringContainsString('cbox_vid=', $html);
    }

    public function test_the_base_table_cta_carries_no_attribution_when_no_experiment_runs(): void
    {
        [$base] = $this->tables();
        $base->forceFill(['cta_url_template' => 'https://shop.test/buy?plan={plan}'])->save();

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        $this->assertStringNotContainsString('cbox_exp=', $html);
        $this->assertStringNotContainsString('cbox_var=', $html);
    }

    public function test_a_control_weighted_experiment_serves_the_base_table(): void
    {
        [$base, $challenger] = $this->tables();
        $this->experiment($base, $challenger, ExperimentStatus::Running, 1, 0); // all traffic → control (base)

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        $this->assertStringContainsString('Team', $html);
        $this->assertStringNotContainsString('Business', $html);
    }

    public function test_impression_is_recorded_once_per_visitor(): void
    {
        [$base, $challenger] = $this->tables();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Running, 0, 1);

        $resolver = app(StorefrontExperimentResolver::class);

        // The same visitor resolved three times records exactly one impression (deduped).
        $resolver->resolve($base->fresh(), 'visitor-fixed');
        $resolver->resolve($base->fresh(), 'visitor-fixed');
        $resolver->resolve($base->fresh(), 'visitor-fixed');

        $this->assertSame(1, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());

        // A different visitor adds a second, distinct impression.
        $resolver->resolve($base->fresh(), 'visitor-other');
        $this->assertSame(2, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());
    }

    public function test_a_draft_experiment_does_not_serve_variants(): void
    {
        [$base, $challenger] = $this->tables();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Draft, 0, 1);

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        // The base table is served (Team), never the challenger, and no impression is recorded.
        $this->assertStringContainsString('Team', $html);
        $this->assertStringNotContainsString('Business', $html);
        $this->assertSame(0, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());
    }

    public function test_a_concluded_experiment_without_a_winner_serves_the_base_table(): void
    {
        [$base, $challenger] = $this->tables();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Concluded, 0, 1);
        $experiment->forceFill(['concluded_at' => now(), 'promoted_variant_id' => null])->save();

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        $this->assertStringContainsString('Team', $html);
        $this->assertStringNotContainsString('Business', $html);
        $this->assertSame(0, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());
    }

    public function test_a_concluded_experiment_with_a_promoted_winner_serves_the_winners_table(): void
    {
        [$base, $challenger] = $this->tables();
        $experiment = $this->experiment($base, $challenger, ExperimentStatus::Concluded, 1, 1);
        $winner = $experiment->variants->firstWhere('is_control', false);
        $experiment->forceFill(['concluded_at' => now(), 'promoted_variant_id' => $winner->id])->save();

        $html = (string) $this->get('/pricing/plans')->assertOk()->getContent();

        // The page now serves the winner's table (Business), but records no impression (test over).
        $this->assertStringContainsString('Business', $html);
        $this->assertSame(0, ExperimentImpression::query()->where('experiment_id', $experiment->id)->count());
    }
}
