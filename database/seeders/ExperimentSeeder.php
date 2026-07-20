<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\ExperimentResults;
use App\Billing\Experiments\ImpressionRecorder;
use App\Billing\Experiments\VariantAssigner;
use App\Models\Experiment;
use App\Models\ExperimentConversion;
use App\Models\ExperimentVariant;
use App\Models\PricingTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A demo A/B pricing experiment on the seeded "plans" table, populated with a deterministic
 * synthetic traffic run so the console dashboard renders real impressions/conversions/lift on a
 * fresh install. It:
 *
 *  1. mints an "annual-first" challenger pricing table (the same catalog, Business featured
 *     instead of Team) so the two arms serve genuinely different pages;
 *  2. creates a running experiment (control = the base table, challenger = the annual-first
 *     table) optimising checkout completion;
 *  3. drives a fixed set of synthetic visitors through the REAL {@see VariantAssigner} +
 *     {@see ImpressionRecorder} (so the demo dogfoods the assignment path), then converts a
 *     seeded fraction of each arm — the challenger a little higher — to show a positive lift.
 *
 * Everything is deterministic (a fixed visitor-id sequence, a fixed per-arm conversion stride),
 * so the seeded numbers are stable across installs. Idempotent: it no-ops if the experiment
 * already exists.
 */
class ExperimentSeeder extends Seeder
{
    /** Synthetic visitors to run through assignment. */
    private const int VISITORS = 600;

    public function run(): void
    {
        $base = PricingTable::query()->where('key', 'plans')->first();

        if (! $base instanceof PricingTable || Experiment::query()->where('key', 'annual-first')->exists()) {
            return;
        }

        $challengerTable = $this->annualFirstTable($base);

        $experiment = Experiment::query()->create([
            'key' => 'annual-first',
            'name' => 'Annual-first layout',
            'hypothesis' => 'Featuring the Business (annual) column lifts checkout completion for growing teams.',
            'status' => ExperimentStatus::Running->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value,
            'pricing_table_id' => $base->id,
            'started_at' => Carbon::now()->subDays(9),
        ]);

        $control = $experiment->variants()->create([
            'label' => 'Control (current)', 'is_control' => true, 'weight' => 1, 'sort_order' => 0,
            'served_pricing_table_id' => null,
        ]);

        $challenger = $experiment->variants()->create([
            'label' => 'Annual-first', 'is_control' => false, 'weight' => 1, 'sort_order' => 1,
            'served_pricing_table_id' => $challengerTable->id,
        ]);

        $this->simulate($experiment, $control, $challenger);
    }

    /**
     * Drive synthetic visitors through the real assigner + impression recorder, then convert a
     * deterministic fraction of each arm (control ≈ 1/9, challenger ≈ 1/6 → a visible positive
     * lift) as checkout-completed conversions.
     */
    private function simulate(Experiment $experiment, ExperimentVariant $control, ExperimentVariant $challenger): void
    {
        $assigner = new VariantAssigner;
        $impressions = new ImpressionRecorder;
        $experiment->load('variants');

        // A per-variant running counter so the conversion cadence is deterministic per arm.
        $seen = [$control->id => 0, $challenger->id => 0];
        $strides = [$control->id => 9, $challenger->id => 6];

        for ($i = 0; $i < self::VISITORS; $i++) {
            $visitorId = 'seed-visitor-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $variant = $assigner->assign($experiment, $visitorId);

            if (! $variant instanceof ExperimentVariant) {
                continue;
            }

            $impressions->record($experiment, $variant, $visitorId);

            $seen[$variant->id]++;

            if ($seen[$variant->id] % $strides[$variant->id] === 0) {
                ExperimentConversion::query()->create([
                    'experiment_id' => $experiment->id,
                    'experiment_variant_id' => $variant->id,
                    'visitor_id' => $visitorId,
                    'kind' => ExperimentMetric::CheckoutCompleted->value,
                    'billing_session_id' => null,
                    'converted_at' => Carbon::now()->subDays(random_int(0, 8)),
                ]);
            }
        }

        // Touch the results read model so a broken projection surfaces at seed time, not first view.
        app(ExperimentResults::class)->for($experiment);
    }

    /** An "annual-first" clone of the base table: the same columns, but Business featured. */
    private function annualFirstTable(PricingTable $base): PricingTable
    {
        $challenger = PricingTable::query()->create([
            'key' => 'plans-annual-first',
            'name' => 'Plans & pricing — annual-first',
            'seller_entity_id' => $base->seller_entity_id,
            'currencies' => $base->currencies,
            'default_currency' => $base->default_currency,
            'interval_toggle' => $base->interval_toggle,
            'cta_label' => $base->cta_label,
            'cta_url_template' => $base->cta_url_template,
            // Not publicly reachable on its own key — it exists only to be served under the test.
            'active' => false,
        ]);

        foreach ($base->columns()->with('plan')->get() as $column) {
            $challenger->columns()->create([
                'plan_id' => $column->plan_id,
                'annual_plan_id' => $column->annual_plan_id,
                'sort_order' => $column->sort_order,
                // Move the featured emphasis to Business (the annual-first thesis).
                'featured' => $column->plan?->key === 'business',
                'badge' => $column->plan?->key === 'business' ? 'Best value' : null,
                'highlight' => $column->highlight,
            ]);
        }

        foreach ($base->featureRows as $row) {
            $challenger->featureRows()->create([
                'feature_id' => $row->feature_id,
                'sort_order' => $row->sort_order,
            ]);
        }

        return $challenger;
    }
}
