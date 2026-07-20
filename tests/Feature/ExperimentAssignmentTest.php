<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\VariantAssigner;
use App\Models\Experiment;
use App\Models\PricingTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deterministic, weighted variant assignment: the same visitor always maps to the same
 * variant (sticky), and a fixed batch of visitor ids distributes ~proportionally to the weights.
 * There is no seed to lose — the split is a pure function of the ids, so these numbers are stable.
 */
class ExperimentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function experiment(int $controlWeight, int $challengerWeight): Experiment
    {
        $base = PricingTable::query()->create(['key' => 'plans', 'name' => 'Plans', 'active' => true]);

        $experiment = Experiment::query()->create([
            'key' => 'weight-test',
            'name' => 'Weight test',
            'status' => ExperimentStatus::Running->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value,
            'pricing_table_id' => $base->id,
        ]);

        $experiment->variants()->create(['label' => 'Control', 'is_control' => true, 'weight' => $controlWeight, 'sort_order' => 0]);
        $experiment->variants()->create(['label' => 'Challenger', 'is_control' => false, 'weight' => $challengerWeight, 'sort_order' => 1]);

        return $experiment->load('variants');
    }

    public function test_the_same_visitor_always_maps_to_the_same_variant(): void
    {
        $experiment = $this->experiment(1, 1);
        $assigner = new VariantAssigner;

        foreach (['visitor-a', 'visitor-b', 'visitor-c', 'e3f9c2', 'another-one'] as $visitorId) {
            $first = $assigner->assign($experiment, $visitorId);
            $this->assertNotNull($first);

            // Re-assigning the same id 20 times never flaps.
            for ($i = 0; $i < 20; $i++) {
                $this->assertSame($first->id, $assigner->assign($experiment, $visitorId)?->id);
            }
        }
    }

    public function test_a_batch_of_visitors_distributes_proportionally_to_the_weights(): void
    {
        // 1 : 3 weights → ~25% / ~75% split.
        $experiment = $this->experiment(1, 3);
        $assigner = new VariantAssigner;

        $control = $experiment->control();
        $this->assertNotNull($control);

        $counts = [];
        $n = 10000;

        for ($i = 0; $i < $n; $i++) {
            $variant = $assigner->assign($experiment, 'visitor-'.$i);
            $this->assertNotNull($variant);
            $counts[$variant->id] = ($counts[$variant->id] ?? 0) + 1;
        }

        $controlShare = ($counts[$control->id] ?? 0) / $n;

        // Expected 0.25; assert within a generous ±0.02 tolerance for this fixed 10k id set.
        $this->assertEqualsWithDelta(0.25, $controlShare, 0.02, sprintf('Control share was %.4f, expected ~0.25.', $controlShare));
    }

    public function test_an_even_split_is_close_to_fifty_fifty(): void
    {
        $experiment = $this->experiment(1, 1);
        $assigner = new VariantAssigner;

        $control = $experiment->control();
        $this->assertNotNull($control);

        $controlCount = 0;
        $n = 10000;

        for ($i = 0; $i < $n; $i++) {
            if ($assigner->assign($experiment, 'v-'.$i)?->id === $control->id) {
                $controlCount++;
            }
        }

        $this->assertEqualsWithDelta(0.5, $controlCount / $n, 0.02);
    }

    public function test_a_zero_total_weight_assigns_nothing(): void
    {
        $experiment = $this->experiment(0, 0);

        $this->assertNull((new VariantAssigner)->assign($experiment, 'visitor-x'));
    }
}
