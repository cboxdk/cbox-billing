<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\QuoteCalculator;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CPQ pricing engine: a two-line quote (a per-seat plan @ N seats + a one-off) computes the
 * correct tax-aware first invoice and the committed contract value through the engine — exact
 * minor units, never a fabricated number.
 */
class QuoteCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function pricedPlan(int $unitMinor): Plan
    {
        $product = Product::query()->create(['key' => 'cpq-prod', 'name' => 'CPQ Product', 'shape' => 'recurring']);
        $plan = Plan::query()->create(['product_id' => $product->id, 'key' => 'cpq-seat', 'name' => 'Seat Plan', 'interval' => 'month', 'active' => true]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => $unitMinor, 'pricing_model' => 'per_unit']);

        return $plan->load('prices');
    }

    private function dkOrg(): Organization
    {
        return Organization::query()->create([
            'id' => 'org_cpq', 'name' => 'CPQ Buyer ApS', 'billing_email' => 'ap@cpq.example',
            'billing_country' => 'DK', 'tax_id' => 'DK99999999', 'tax_id_validated' => true,
        ]);
    }

    private function twoLineQuote(Plan $plan, Organization $org, int $seats, int $oneOffMinor, ?int $commitmentMinor): Quote
    {
        $quote = Quote::query()->create([
            'number' => 'Q-T0001', 'organization_id' => $org->id, 'status' => QuoteStatus::Draft,
            'currency' => 'DKK', 'term_count' => 12, 'term_unit' => 'month', 'billing_interval' => 'monthly',
            'minimum_commitment_minor' => $commitmentMinor,
        ]);
        $quote->lines()->create(['sort_order' => 0, 'type' => QuoteLineType::Plan, 'plan_id' => $plan->id, 'quantity' => $seats, 'recurring' => true]);
        $quote->lines()->create(['sort_order' => 1, 'type' => QuoteLineType::Custom, 'description' => 'Onboarding', 'quantity' => 1, 'unit_amount_minor' => $oneOffMinor, 'recurring' => false]);

        return $quote->load('lines');
    }

    public function test_two_line_quote_computes_tax_aware_total_and_committed_value(): void
    {
        $plan = $this->pricedPlan(20000); // 200.00 DKK per seat
        $org = $this->dkOrg();
        $quote = $this->twoLineQuote($plan, $org, 10, 50000, 300000); // 10 seats + 500.00 one-off, 3000.00/period floor

        $c = app(QuoteCalculator::class)->compute($quote);

        // Recurring net = 10 × 200.00 = 2000.00; one-off = 500.00; first-invoice net = 2500.00.
        $this->assertSame(200000, $c->recurringNet->minor());
        $this->assertSame(250000, $c->firstInvoiceNet->minor());

        // DK domestic B2B VAT is 25%: tax = 625.00, gross = 3125.00.
        $this->assertFalse($c->taxPending, 'DK jurisdiction should resolve tax.');
        $this->assertSame(62500, $c->firstInvoiceTax->minor());
        $this->assertSame(312500, $c->firstInvoiceGross->minor());

        // Committed value: recurring 2000.00 < 3000.00 floor, so each of 12 periods is floored to
        // 3000.00 through the engine MinimumCommitment → 36000.00 committed.
        $this->assertSame(12, $c->periods);
        $this->assertSame(3_600_000, $c->committedNet->minor());
        $this->assertCount(2, $c->lines);
    }

    public function test_committed_value_without_a_commitment_is_the_recurring_over_the_term(): void
    {
        $plan = $this->pricedPlan(20000);
        $org = $this->dkOrg();
        $quote = $this->twoLineQuote($plan, $org, 10, 50000, null);

        $c = app(QuoteCalculator::class)->compute($quote);

        // No floor: 2000.00 recurring × 12 periods = 24000.00.
        $this->assertSame(2_400_000, $c->committedNet->minor());
    }

    public function test_a_ramp_schedule_steps_the_committed_value(): void
    {
        $plan = $this->pricedPlan(20000);
        $org = $this->dkOrg();
        $quote = $this->twoLineQuote($plan, $org, 10, 0, null);
        // Periods 0–5 at 2000.00, periods 6–11 at 2500.00 → 6×2000 + 6×2500 = 27000.00.
        $quote->update(['ramp' => [
            ['from_period_index' => 0, 'amount_minor' => 200000],
            ['from_period_index' => 6, 'amount_minor' => 250000],
        ]]);

        $c = app(QuoteCalculator::class)->compute($quote->fresh());

        $this->assertSame(2_700_000, $c->committedNet->minor());
    }
}
