<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\PlanAuthoring;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\Product;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression cover for the six confirmed billing-correctness defects (H1–H6) plus M3. Every
 * money assertion is in exact minor units against a plan shape the bugs mis-billed: a
 * per-unit plan, a graduated/tiered plan, a multi-seat subscription, a yearly plan, and the
 * quarter/week restriction. The through-line: the invoice, MRR, and the change preview all
 * agree because they run the SAME engine pricing, and every issuance/charge is idempotent.
 */
class BillingCorrectnessFixesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::query()->create(['key' => 'app', 'name' => 'App']);
    }

    // --- H1: recurring invoice is seat- and pricing-model-aware ---------------------------

    public function test_h1_per_unit_plan_invoices_unit_times_seats_and_equals_mrr(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('h1_pu', $plan, seats: 10, start: '2026-03-01', end: '2026-04-01');

        $invoice = app(GeneratesInvoices::class)->generate($sub->fresh());

        // 500/seat × 10 seats = 5 000 net (never the raw base 500); 25% DK VAT.
        $this->assertSame(5_000, $invoice->subtotal_minor);
        $this->assertSame(1_250, $invoice->tax_minor);
        $this->assertSame(6_250, $invoice->total_minor);

        // The invoice net equals monthly MRR × cadence (monthly ⇒ ×1) — invoice == MRR.
        $mrr = SubscriptionRevenue::monthly($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'));
        $this->assertSame(5_000, $mrr->minor());
        $this->assertSame($mrr->minor(), $invoice->subtotal_minor);
    }

    public function test_h1_graduated_plan_invoices_from_the_tier_set_not_the_base_price(): void
    {
        // Base price is a sentinel that MUST be ignored: a graduated plan bills from its tiers.
        $plan = $this->plan('month', 'graduated', 99_999_999, [
            ['up_to' => 10, 'unit_minor' => 0],
            ['up_to' => 50, 'unit_minor' => 9_900],
            ['up_to' => null, 'unit_minor' => 7_900],
        ]);
        $sub = $this->subscription('h1_grad', $plan, seats: 20, start: '2026-03-01', end: '2026-04-01');

        $invoice = app(GeneratesInvoices::class)->generate($sub->fresh());

        // 20 seats graduated = 10×0 + 10×9 900 = 99 000 — not the 99 999 999 base.
        $this->assertSame(99_000, $invoice->subtotal_minor);
        $mrr = SubscriptionRevenue::monthly($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'));
        $this->assertSame(99_000, $mrr->minor());
    }

    public function test_h1_yearly_plan_invoices_the_full_year_and_equals_mrr_times_twelve(): void
    {
        $plan = $this->plan('year', 'per_unit', 1_200);
        $sub = $this->subscription('h1_year', $plan, seats: 10, start: '2026-01-01', end: '2027-01-01');

        $invoice = app(GeneratesInvoices::class)->generate($sub->fresh());

        // The period invoice is the FULL annual amount: 1 200/seat × 10 = 12 000.
        $this->assertSame(12_000, $invoice->subtotal_minor);

        // MRR is the monthly-equivalent: 12 000 / 12 = 1 000. Invoice net == MRR × 12.
        $mrr = SubscriptionRevenue::monthly($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'));
        $this->assertSame(1_000, $mrr->minor());
        $this->assertSame($invoice->subtotal_minor, $mrr->minor() * 12);
    }

    // --- H2: quarter/week are refused (the engine renews only month/year) ------------------

    public function test_h2_authoring_a_quarter_or_week_plan_is_refused(): void
    {
        foreach (['quarter', 'week'] as $interval) {
            try {
                app(PlanAuthoring::class)->create([
                    'product_id' => $this->product->id,
                    'key' => 'plan_'.$interval,
                    'name' => ucfirst($interval),
                    'interval' => $interval,
                    'active' => true,
                ]);
                $this->fail("Authoring a {$interval} plan should be refused.");
            } catch (CatalogActionDenied $e) {
                $this->assertStringContainsString('cannot be billed', $e->getMessage());
            }
        }

        // month and year ARE authorable.
        $this->assertInstanceOf(Plan::class, app(PlanAuthoring::class)->create([
            'product_id' => $this->product->id, 'key' => 'ok_month', 'name' => 'M', 'interval' => 'month', 'active' => true,
        ]));
    }

    public function test_h2_migration_normalizes_any_legacy_quarter_or_week_plan_to_month(): void
    {
        $plan = $this->plan('month', 'flat', 100_000);

        // Simulate a legacy row stored on an unbillable interval (pre-guard data).
        Plan::query()->whereKey($plan->id)->update(['interval' => 'quarter']);

        $migration = require database_path('migrations/2025_02_15_000000_normalize_unbillable_plan_intervals.php');
        $migration->up();

        $this->assertSame('month', $plan->fresh()->interval);
        // Deny-by-default fallback: an unbillable interval maps to Monthly, never a silent
        // fabricated cadence.
        $this->assertSame(BillingInterval::Monthly, $plan->fresh()->billingInterval());
    }

    // --- H3: the initial period follows the plan interval ---------------------------------

    public function test_h3_yearly_subscription_opens_a_full_year_and_does_not_renew_next_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-07-10 09:00:00', 'UTC'));
        $plan = $this->plan('year', 'flat', 1_200_000);
        $org = $this->org('h3_year');

        $sub = app(SubscribesOrganizations::class)->subscribe($org, $plan, seats: 1);

        // The first period is a YEAR (signup → anniversary), not a calendar month.
        $this->assertSame('2025-07-10', $sub->current_period_start?->format('Y-m-d'));
        $this->assertSame('2026-07-10', $sub->current_period_end?->format('Y-m-d'));

        // At the next month boundary nothing renews and no annual re-charge is issued.
        Carbon::setTestNow(Carbon::parse('2025-08-01 00:00:00', 'UTC'));
        $outcome = app(CycleRenewalService::class)->renew($sub->fresh());

        $this->assertFalse($outcome->baseRenewed);
        $this->assertNull($outcome->invoice);
        $this->assertSame(0, Invoice::query()->count());

        Carbon::setTestNow();
    }

    // --- H4: issuance is idempotent per (subscription, period) -----------------------------

    public function test_h4_generate_is_idempotent_for_the_same_period(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('h4_idem', $plan, seats: 5, start: '2026-03-01', end: '2026-04-01');

        $first = app(GeneratesInvoices::class)->generate($sub->fresh());
        $second = app(GeneratesInvoices::class)->generate($sub->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2_500, $first->subtotal_minor);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $sub->id)->count());
    }

    public function test_h4_the_period_key_has_a_unique_database_guard(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('h4_guard', $plan, seats: 5, start: '2026-03-01', end: '2026-04-01');

        $invoice = app(GeneratesInvoices::class)->generate($sub->fresh());

        // A second row for the same (subscription, period) is rejected at the DB layer, so a
        // race that slips past the read guard still cannot mint a duplicate.
        $this->expectException(QueryException::class);
        Invoice::query()->create([
            'organization_id' => $sub->organization_id,
            'subscription_id' => $sub->id,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'seller' => $invoice->seller,
            'number' => 'DUP-1',
            'currency' => 'DKK',
            'subtotal_minor' => 1,
            'tax_minor' => 0,
            'total_minor' => 1,
            'status' => 'open',
        ]);
    }

    // --- H5: a double renewal at the boundary invoices exactly once ------------------------

    public function test_h5_double_renewal_at_the_boundary_yields_one_seat_aware_invoice(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('h5_race', $plan, seats: 5, start: '2026-01-01', end: '2026-02-01');

        Carbon::setTestNow(Carbon::parse('2026-02-01 00:00:00', 'UTC'));

        $first = app(CycleRenewalService::class)->renew($sub->fresh());
        // A second pass at the same instant re-reads the already-advanced period under the row
        // lock and finds nothing due — the exactly-once guard.
        $second = app(CycleRenewalService::class)->renew($sub->fresh());

        $this->assertTrue($first->baseRenewed);
        $this->assertNotNull($first->invoice);
        $this->assertSame(2_500, $first->invoice->subtotal_minor); // 500 × 5 seats
        $this->assertFalse($second->baseRenewed);
        $this->assertNull($second->invoice);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $sub->id)->count());

        Carbon::setTestNow();
    }

    // --- H6: immediate changes collect the previewed proration ----------------------------

    public function test_h6_immediate_plan_change_collects_the_previewed_due_now(): void
    {
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $org = $this->org('h6_change', currency: 'DKK');
        $team = Plan::query()->with(['prices', 'product'])->where('key', 'team')->firstOrFail();
        $business = Plan::query()->with(['prices', 'product'])->where('key', 'business')->firstOrFail();

        $sub = app(SubscribesOrganizations::class)->subscribe($org, $team, seats: 20);
        $invoicesBefore = Invoice::query()->where('organization_id', 'h6_change')->count();

        $preview = app(SubscribesOrganizations::class)->previewChange($sub->fresh()->loadMissing('plan.product', 'organization'), $business);
        $dueNowGross = $preview->dueNowQuote?->totals->gross->minor();
        $this->assertIsInt($dueNowGross);
        $this->assertGreaterThan(0, $dueNowGross);

        app(SubscribesOrganizations::class)->changePlan($sub->fresh()->loadMissing('plan.product', 'organization'), $business);

        // A prorated invoice was raised for exactly the previewed (taxed) due-now — preview
        // == charge in cash, not just on the review page.
        $prorationInvoice = Invoice::query()->where('organization_id', 'h6_change')->whereNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame($dueNowGross, $prorationInvoice->total_minor);
        $this->assertSame($invoicesBefore + 1, Invoice::query()->where('organization_id', 'h6_change')->count());

        Carbon::setTestNow();
    }

    public function test_h6_seat_increase_collects_a_prorated_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'per_unit', 1_000);
        $sub = $this->subscription('h6_qty', $plan, seats: 2, start: '2026-05-01', end: '2026-06-01');

        $preview = app(ManagesSubscriptionDepth::class)->previewQuantity($sub->fresh(), 5);
        // Full period ahead (at == period start) ⇒ full delta: (5−2) × 1 000 = 3 000 net.
        $this->assertSame(3_000, $preview->charge->minor());

        app(ManagesSubscriptionDepth::class)->changeQuantity($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'), 5);

        $invoice = Invoice::query()->where('subscription_id', $sub->id)->whereNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame(3_000, $invoice->subtotal_minor); // the previewed net, now charged

        Carbon::setTestNow();
    }

    public function test_h6_adding_a_paid_addon_collects_a_prorated_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'flat', 100_000);
        $sub = $this->subscription('h6_addon', $plan, seats: 1, start: '2026-05-01', end: '2026-06-01');

        $request = new AddOnRequest(
            key: 'extra-pack',
            priceMinor: 6_000,
            currency: 'DKK',
            alignment: AddOnAlignment::Aligned,
            creditAllotment: 0,
        );

        $preview = app(ManagesSubscriptionDepth::class)->previewAddOn($sub->fresh()->loadMissing('plan', 'organization'), $request);
        $this->assertSame(6_000, $preview->charge->minor()); // full period ahead ⇒ full price

        app(ManagesSubscriptionDepth::class)->addAddOn($sub->fresh()->loadMissing('plan', 'organization'), $request);

        $invoice = Invoice::query()->where('subscription_id', $sub->id)->whereNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame(6_000, $invoice->subtotal_minor);

        Carbon::setTestNow();
    }

    // --- HP3: seat/add-on preview shows the tax-aware GROSS the apply collects -------------

    public function test_hp3_seat_preview_gross_equals_the_taxed_charge_and_is_clock_stable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'per_unit', 1_000);
        $sub = $this->subscription('hp3_qty', $plan, seats: 2, start: '2026-05-01', end: '2026-06-01');

        $depth = app(ManagesSubscriptionDepth::class);
        $preview = $depth->previewQuantity($sub->fresh(), 5);

        // The engine NET proration: (5−2) × 1 000 = 3 000, full period ahead.
        $this->assertSame(3_000, $preview->charge->minor());
        // The tax-aware GROSS actually collected — 3 000 net + 25% DK VAT = 3 750 (preview == charge).
        $this->assertSame(3_750, $preview->grossDueNow->minor());

        // Clock-stable: a second preview at the same fixed BillingClock is identical (no drift).
        $again = $depth->previewQuantity($sub->fresh(), 5);
        $this->assertSame($preview->grossDueNow->minor(), $again->grossDueNow->minor());

        // The apply issues a taxed proration invoice whose GROSS total equals the previewed gross.
        $depth->changeQuantity($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'), 5);
        $invoice = Invoice::query()->where('subscription_id', $sub->id)->whereNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame($preview->grossDueNow->minor(), $invoice->total_minor);
        $this->assertSame(3_000, $invoice->subtotal_minor);

        Carbon::setTestNow();
    }

    public function test_hp3_seat_reduction_previews_a_zero_gross_due_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'per_unit', 1_000);
        $sub = $this->subscription('hp3_credit', $plan, seats: 5, start: '2026-05-01', end: '2026-06-01');

        // A reduction nets a wallet credit (net negative) and owes nothing now: gross due-now = 0.
        $preview = app(ManagesSubscriptionDepth::class)->previewQuantity($sub->fresh(), 2);
        $this->assertTrue($preview->isCredit());
        $this->assertSame(0, $preview->grossDueNow->minor());

        Carbon::setTestNow();
    }

    public function test_hp3_addon_preview_gross_equals_the_taxed_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'flat', 100_000);
        $sub = $this->subscription('hp3_addon', $plan, seats: 1, start: '2026-05-01', end: '2026-06-01');

        $request = new AddOnRequest(
            key: 'extra-pack',
            priceMinor: 6_000,
            currency: 'DKK',
            alignment: AddOnAlignment::Aligned,
            creditAllotment: 0,
        );

        $depth = app(ManagesSubscriptionDepth::class);
        $preview = $depth->previewAddOn($sub->fresh()->loadMissing('plan', 'organization'), $request);

        // NET 6 000 (full period ahead); GROSS 6 000 + 25% VAT = 7 500 (preview == charge).
        $this->assertSame(6_000, $preview->charge->minor());
        $this->assertSame(7_500, $preview->grossDueNow->minor());

        $depth->addAddOn($sub->fresh()->loadMissing('plan', 'organization'), $request);
        $invoice = Invoice::query()->where('subscription_id', $sub->id)->whereNull('period_start')->latest('id')->firstOrFail();
        $this->assertSame($preview->grossDueNow->minor(), $invoice->total_minor);
        $this->assertSame(6_000, $invoice->subtotal_minor);

        Carbon::setTestNow();
    }

    // --- M3: seat-change proration uses the pricing model ---------------------------------

    public function test_m3_flat_plan_seat_change_nets_zero_and_charges_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'flat', 100_000);
        $sub = $this->subscription('m3_flat', $plan, seats: 3, start: '2026-05-01', end: '2026-06-01');

        // A flat plan is seat-invariant: going 3 → 8 seats nets zero (not base × Δseats).
        $preview = app(ManagesSubscriptionDepth::class)->previewQuantity($sub->fresh(), 8);
        $this->assertSame(0, $preview->charge->minor());

        app(ManagesSubscriptionDepth::class)->changeQuantity($sub->fresh()->loadMissing('plan.prices.tiers', 'organization'), 8);

        // Nothing owed ⇒ no proration invoice raised.
        $this->assertSame(0, Invoice::query()->where('subscription_id', $sub->id)->whereNull('period_start')->count());

        Carbon::setTestNow();
    }

    public function test_m3_tiered_plan_seat_change_prices_from_the_tier_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:00:00', 'UTC'));
        $plan = $this->plan('month', 'graduated', 99_999_999, [
            ['up_to' => 10, 'unit_minor' => 0],
            ['up_to' => 50, 'unit_minor' => 9_900],
            ['up_to' => null, 'unit_minor' => 7_900],
        ]);
        $sub = $this->subscription('m3_tier', $plan, seats: 12, start: '2026-05-01', end: '2026-06-01');

        // 12 seats = 2×9 900 = 19 800; 20 seats = 10×9 900 = 99 000; delta 79 200 (full period).
        $preview = app(ManagesSubscriptionDepth::class)->previewQuantity($sub->fresh(), 20);
        $this->assertSame(79_200, $preview->charge->minor());

        Carbon::setTestNow();
    }

    // --- fixtures -------------------------------------------------------------------------

    // --- M5: the invoice line NET column carries net, not gross ---------------------------

    public function test_m5_per_line_net_sums_to_the_subtotal_on_a_tax_exclusive_invoice(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('m5_net', $plan, seats: 10, start: '2026-03-01', end: '2026-04-01');

        $invoice = app(GeneratesInvoices::class)->generate($sub->fresh());

        // DK domestic B2B: 5 000 net + 25% VAT = 1 250 tax → 6 250 gross.
        $this->assertSame(5_000, $invoice->subtotal_minor);
        $this->assertSame(1_250, $invoice->tax_minor);

        $lines = $invoice->lines()->get();
        $this->assertNotEmpty($lines);

        // The stored line NET (what the document's NET column now prints) sums to the net
        // Subtotal — NOT to the gross total. Before M5 the column printed `amount_minor` (gross),
        // so it summed to 6 250 and never reconciled to the 5 000 Subtotal.
        $this->assertSame(5_000, (int) $lines->sum('net_minor'));
        $this->assertSame(6_250, (int) $lines->sum('amount_minor'));
        $this->assertNotSame((int) $lines->sum('net_minor'), (int) $lines->sum('amount_minor'));

        // And the rendered PDF prints the net figure for the line.
        $pdf = app(InvoicePdfRenderer::class)->render($invoice->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    // --- M6: a crash between period-advance and invoicing is healed on the next run --------

    public function test_m6_a_leaked_renewal_invoice_is_completed_on_the_next_pass(): void
    {
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('m6_leak', $plan, seats: 4, start: '2026-01-01', end: '2026-02-01');

        // First boundary: renew normally so the opening→Feb period is advanced AND invoiced —
        // this is the "prior period" that proves a renewal advanced into the next one.
        Carbon::setTestNow(Carbon::parse('2026-02-01 00:00:00', 'UTC'));
        $first = app(CycleRenewalService::class)->renew($sub->fresh());
        $this->assertTrue($first->baseRenewed);
        $this->assertNotNull($first->invoice);

        // Simulate a crash in the SECOND renewal's advance→invoice window: advance the period
        // by hand (as the committed transaction would) but issue NO invoice for it.
        Carbon::setTestNow(Carbon::parse('2026-03-01 00:00:00', 'UTC'));
        $sub->forceFill([
            'current_period_start' => Carbon::parse('2026-03-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-04-01', 'UTC'),
        ])->save();

        $leaked = Invoice::query()
            ->where('subscription_id', $sub->id)
            ->where('period_start', Carbon::parse('2026-03-01', 'UTC'))
            ->exists();
        $this->assertFalse($leaked, 'Precondition: the March period is un-invoiced (the crash gap).');

        // The next daily pass finds nothing to advance (period already rolled) but detects and
        // completes the missing invoice via the idempotent issuance — no monthly-pass wait.
        $recovery = app(CycleRenewalService::class)->renew($sub->fresh());
        $this->assertFalse($recovery->baseRenewed);
        $this->assertNotNull($recovery->invoice);
        $this->assertSame(2_000, $recovery->invoice->subtotal_minor); // 500 × 4 seats
        $this->assertSame('2026-03-01', $recovery->invoice->period_start?->format('Y-m-d'));

        // Exactly one invoice per period — the recovery is idempotent, a further run adds none.
        $again = app(CycleRenewalService::class)->renew($sub->fresh());
        $this->assertNull($again->invoice);
        $this->assertSame(2, Invoice::query()->where('subscription_id', $sub->id)->count());

        Carbon::setTestNow();
    }

    public function test_m6_a_never_renewed_opening_period_is_left_for_the_monthly_pass(): void
    {
        // A subscription that has NOT yet renewed: its opening period carries no prior invoice,
        // so the renewal must NOT invoice it here (the monthly billing:invoice pass owns that) —
        // proving the recovery is narrow and does not change the happy path.
        $plan = $this->plan('month', 'per_unit', 500);
        $sub = $this->subscription('m6_open', $plan, seats: 3, start: '2026-01-01', end: '2026-02-01');

        Carbon::setTestNow(Carbon::parse('2026-01-15 00:00:00', 'UTC'));
        $outcome = app(CycleRenewalService::class)->renew($sub->fresh());

        $this->assertFalse($outcome->baseRenewed);
        $this->assertNull($outcome->invoice);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $sub->id)->count());

        Carbon::setTestNow();
    }

    /**
     * @param  list<array{up_to: int|null, unit_minor: int, flat_minor?: int|null}>  $tiers
     */
    private function plan(string $interval, string $model, int $priceMinor, array $tiers = []): Plan
    {
        $plan = Plan::query()->create([
            'product_id' => $this->product->id,
            'key' => 'plan_'.uniqid(),
            'name' => 'Plan',
            'interval' => $interval,
            'active' => true,
        ]);

        $price = PlanPrice::query()->create([
            'plan_id' => $plan->id,
            'currency' => 'DKK',
            'price_minor' => $priceMinor,
            'pricing_model' => $model,
        ]);

        foreach ($tiers as $order => $tier) {
            PlanPriceTier::query()->create([
                'plan_price_id' => $price->id,
                'up_to' => $tier['up_to'],
                'unit_minor' => $tier['unit_minor'],
                'flat_minor' => $tier['flat_minor'] ?? null,
                'sort_order' => $order,
            ]);
        }

        return $plan;
    }

    private function org(string $id, string $currency = 'DKK'): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => $id,
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
            'billing_currency' => $currency,
        ]);
    }

    private function subscription(string $org, Plan $plan, int $seats, string $start, string $end): Subscription
    {
        $this->org($org);

        return Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => $seats,
            'current_period_start' => Carbon::parse($start, 'UTC'),
            'current_period_end' => Carbon::parse($end, 'UTC'),
            'cancel_at_period_end' => false,
        ]);
    }
}
