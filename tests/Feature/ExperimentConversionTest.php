<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Experiments\ConversionAttribution;
use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Billing\Experiments\ExperimentResults;
use App\Models\BillingSession;
use App\Models\Experiment;
use App\Models\ExperimentConversion;
use App\Models\ExperimentImpression;
use App\Models\ExperimentVariant;
use App\Models\PricingTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Conversion attribution + the results read model: a checkout start attributes to the assigned
 * variant, a settlement completes it, both are idempotent (a double settlement never double-counts),
 * and the results model computes exact impressions/conversions/rate and the two-proportion-z
 * significance for a seeded set.
 */
class ExperimentConversionTest extends TestCase
{
    use RefreshDatabase;

    private Experiment $experiment;

    private ExperimentVariant $control;

    private ExperimentVariant $challenger;

    protected function setUp(): void
    {
        parent::setUp();

        $base = PricingTable::query()->create(['key' => 'plans', 'name' => 'Plans', 'active' => true]);

        $this->experiment = Experiment::query()->create([
            'key' => 'convert-test',
            'name' => 'Convert test',
            'status' => ExperimentStatus::Running->value,
            'primary_metric' => ExperimentMetric::CheckoutCompleted->value,
            'pricing_table_id' => $base->id,
            'started_at' => now(),
        ]);

        $this->control = $this->experiment->variants()->create(['label' => 'Control', 'is_control' => true, 'weight' => 1, 'sort_order' => 0]);
        $this->challenger = $this->experiment->variants()->create(['label' => 'Challenger', 'is_control' => false, 'weight' => 1, 'sort_order' => 1]);
    }

    private function checkoutSession(): BillingSession
    {
        return BillingSession::query()->create([
            'token' => 'tok_'.uniqid(),
            'organization_id' => 'org_'.uniqid(),
            'type' => 'checkout',
            'plan_key' => 'team',
            'return_url' => 'https://app.test/return',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_a_checkout_start_then_settlement_attributes_to_the_variant_and_is_idempotent(): void
    {
        $attribution = app(ConversionAttribution::class);
        $session = $this->checkoutSession();

        // Start — recorded once even if the link is replayed.
        $attribution->recordCheckoutStart($session, 'convert-test', $this->challenger->id, 'visitor-1');
        $attribution->recordCheckoutStart($session, 'convert-test', $this->challenger->id, 'visitor-1');

        $this->assertSame(1, ExperimentConversion::query()
            ->where('experiment_variant_id', $this->challenger->id)
            ->where('kind', ExperimentMetric::CheckoutStarted->value)
            ->count());

        // Settlement — recorded once even under a re-delivered webhook (double settlement).
        $attribution->recordSettlement($session);
        $attribution->recordSettlement($session);

        $completed = ExperimentConversion::query()
            ->where('experiment_variant_id', $this->challenger->id)
            ->where('kind', ExperimentMetric::CheckoutCompleted->value)
            ->get();

        $this->assertCount(1, $completed);
        $this->assertSame('visitor-1', $completed->first()->visitor_id);
        $this->assertSame($session->id, $completed->first()->billing_session_id);
    }

    public function test_attribution_to_a_non_running_experiment_records_nothing(): void
    {
        $this->experiment->forceFill(['status' => ExperimentStatus::Concluded->value])->save();

        app(ConversionAttribution::class)->recordCheckoutStart($this->checkoutSession(), 'convert-test', $this->challenger->id, 'visitor-9');

        $this->assertSame(0, ExperimentConversion::query()->count());
    }

    public function test_attribution_with_a_mismatched_key_records_nothing(): void
    {
        app(ConversionAttribution::class)->recordCheckoutStart($this->checkoutSession(), 'wrong-key', $this->challenger->id, 'visitor-9');

        $this->assertSame(0, ExperimentConversion::query()->count());
    }

    public function test_settlement_without_a_prior_start_records_no_completion(): void
    {
        // A settled session that never carried attribution attributes nothing.
        app(ConversionAttribution::class)->recordSettlement($this->checkoutSession());

        $this->assertSame(0, ExperimentConversion::query()->count());
    }

    public function test_results_compute_exact_counts_rate_lift_and_significance(): void
    {
        // Control: 100 impressions / 10 conversions (10%). Challenger: 100 / 20 (20%).
        $this->seedImpressions($this->control, 100);
        $this->seedImpressions($this->challenger, 100);
        $this->seedConversions($this->control, 10);
        $this->seedConversions($this->challenger, 20);

        $result = app(ExperimentResults::class)->for($this->experiment->fresh()->load('variants'));

        $this->assertSame(200, $result->totalImpressions);
        $this->assertSame(30, $result->totalConversions);

        $control = $result->control();
        $this->assertNotNull($control);
        $this->assertSame(100, $control->impressions);
        $this->assertSame(10, $control->conversions);
        $this->assertEqualsWithDelta(0.10, $control->rate, 1e-9);
        $this->assertNull($control->lift, 'The control is the baseline — no lift against itself.');

        $challenger = collect($result->variants)->firstWhere('isControl', false);
        $this->assertNotNull($challenger);
        $this->assertSame(100, $challenger->impressions);
        $this->assertSame(20, $challenger->conversions);
        $this->assertEqualsWithDelta(0.20, $challenger->rate, 1e-9);
        $this->assertEqualsWithDelta(1.0, $challenger->lift, 1e-9, '20% vs 10% is a +100% lift.');

        // Two-proportion z for 20/100 vs 10/100 ≈ 1.9803, significant at 95%.
        $this->assertEqualsWithDelta(1.9802950, $challenger->significance->zScore, 1e-4);
        $this->assertTrue($challenger->significance->significant);

        // The leader is the significant challenger.
        $this->assertNotNull($result->leader());
        $this->assertSame($this->challenger->id, $result->leader()->variant->id);
    }

    public function test_results_count_only_the_primary_metric_kind(): void
    {
        $this->seedImpressions($this->challenger, 50);
        // Started conversions must NOT count when the primary metric is checkout-completed.
        $this->seedConversions($this->challenger, 8, ExperimentMetric::CheckoutStarted);
        $this->seedConversions($this->challenger, 3, ExperimentMetric::CheckoutCompleted);

        $result = app(ExperimentResults::class)->for($this->experiment->fresh()->load('variants'));
        $challenger = collect($result->variants)->firstWhere('isControl', false);

        $this->assertSame(3, $challenger->conversions, 'Only checkout_completed rows count under that primary metric.');
    }

    private function seedImpressions(ExperimentVariant $variant, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ExperimentImpression::query()->create([
                'experiment_id' => $this->experiment->id,
                'experiment_variant_id' => $variant->id,
                'visitor_id' => 'imp-'.$variant->id.'-'.$i,
                'first_seen_at' => Carbon::now(),
            ]);
        }
    }

    private function seedConversions(ExperimentVariant $variant, int $count, ExperimentMetric $kind = ExperimentMetric::CheckoutCompleted): void
    {
        for ($i = 0; $i < $count; $i++) {
            ExperimentConversion::query()->create([
                'experiment_id' => $this->experiment->id,
                'experiment_variant_id' => $variant->id,
                'visitor_id' => 'conv-'.$kind->value.'-'.$variant->id.'-'.$i,
                'kind' => $kind->value,
                'converted_at' => Carbon::now(),
            ]);
        }
    }
}
