<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\RevenueAnalytics;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionMrrMovement;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The revenue-analytics screens (engine Reporting module) compute the right headline
 * numbers over a real seeded book, and the analytics/catalog/subscriptions screens render
 * the engine-v0.8 surfaces on real data.
 */
class RevenueAnalyticsConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mrr_is_summed_by_the_engine_over_the_seeded_book(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        $line = app(RevenueAnalytics::class)->revenue()->lineFor('DKK');

        // MRR is now SEAT-AWARE — each contributing subscription is priced for its seat count
        // by the same engine calculator that bills it (PlanPrice::amountFor(seats)), not the
        // flat base. Over the seeded book, in DKK minor:
        //   Hverdag  team (graduated)  8 seats → 0        (first 10 seats are the free tier)
        //   Nordwind business (volume) 24 seats → 288.000 (24 × 12.000, the ≤25 tier)
        //   Klarhed  starter (stairstep) 2 seats → 29.000 (the ≤3 bracket)
        //   Fjord    scale (package)  60 seats → 474.000  (ceil(60/10)=6 blocks × 79.000)
        //   Vinter   team (graduated)  6 seats → 0        (free tier)
        // = 791.000 minor. Trialing (Aula), paused (Meridian) and canceled (Söder) contribute
        // nothing. All five contributing subscriptions are still counted (a zero-priced seat
        // count is a real contribution of zero, not an omission).
        $this->assertNotNull($line);
        $this->assertSame(791_000, $line->mrr->minor());
        $this->assertSame(791_000 * 12, $line->arr->minor());
        $this->assertSame(5, $line->subscriptions);
    }

    public function test_mrr_movement_waterfall_reads_recorded_plan_changes(): void
    {
        $subscriber = app(SubscribesOrganizations::class);

        $this->seed(CatalogSeeder::class);
        $business = Plan::query()->where('key', 'business')->firstOrFail();
        $scale = Plan::query()->where('key', 'scale')->firstOrFail();
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        // Subscribe before the window (these "new" movements fall outside it). MRR is
        // seat-aware, so seats are load-bearing: business (volume) @ 5 seats = 60.000
        // (5 × 12.000); scale (package) @ 20 seats = 158.000 (ceil(20/10)=2 × 79.000).
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        $up = $subscriber->subscribe($this->org('org_up'), $business, seats: 5);
        $down = $subscriber->subscribe($this->org('org_down'), $scale, seats: 20);

        // …then a real upgrade (expansion) and downgrade (contraction) inside it. A plan
        // change keeps the seat count, so it is re-priced under the new plan's model.
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
        $subscriber->changePlan($up->refresh()->loadMissing('plan', 'organization'), $scale);
        $subscriber->changePlan($down->refresh()->loadMissing('plan', 'organization'), $starter);

        $waterfall = app(RevenueAnalytics::class)
            ->movement(Carbon::parse('2026-07-23 00:00:00'), Carbon::parse('2026-07-28 23:59:59'))
            ->waterfallFor('DKK');

        $this->assertNotNull($waterfall);
        $this->assertTrue($waterfall->reconciles());

        // up:   business@5 60.000 → scale@5 (package, 1 block) 79.000 = +19.000 expansion.
        // down: scale@20 158.000 → starter@20 (stairstep, ≥11 bracket) 99.000 = −59.000.
        // The subscribe "new" rows are outside the window.
        $this->assertSame(19_000, $waterfall->expansion->minor());
        $this->assertSame(59_000, $waterfall->contraction->minor());
        $this->assertSame(0, $waterfall->new->minor());
        $this->assertSame(218_000, $waterfall->startMrr->minor()); // 60.000 + 158.000
        $this->assertSame(178_000, $waterfall->endMrr->minor());   // 79.000 + 99.000
    }

    public function test_mrr_movement_classifies_new_churn_reactivation_and_retention(): void
    {
        $subscriber = app(SubscribesOrganizations::class);
        $depth = app(ManagesSubscriptionDepth::class);

        $this->seed(CatalogSeeder::class);
        // Business is a volume (per-seat) plan: at 5 seats each account contributes
        // 5 × 12.000 = 60.000 minor — a clean seat-aware amount to classify movements over.
        $business = Plan::query()->where('key', 'business')->firstOrFail();

        // Before the window: a steady account, a to-be-churned account, and an account that
        // pauses (its pause churn lands outside the window).
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $subscriber->subscribe($this->org('org_steady'), $business, seats: 5);
        $churn = $subscriber->subscribe($this->org('org_churn'), $business, seats: 5);
        $winback = $subscriber->subscribe($this->org('org_winback'), $business, seats: 5);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $depth->pause($winback->refresh()->loadMissing('plan', 'organization'));

        // Inside the window: a new logo, a churn, and a win-back (resume from pause).
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));
        $subscriber->subscribe($this->org('org_new'), $business, seats: 5);
        $subscriber->cancel($churn->refresh()->loadMissing('plan', 'organization'), atPeriodEnd: false);
        $depth->resume($winback->refresh()->loadMissing('plan', 'organization'));

        $analytics = app(RevenueAnalytics::class);
        $start = Carbon::parse('2026-07-01 00:00:00');
        $end = Carbon::parse('2026-07-15 00:00:00');

        $waterfall = $analytics->movement($start, $end)->waterfallFor('DKK');
        $this->assertNotNull($waterfall);
        $this->assertTrue($waterfall->reconciles());

        // start = steady + churn = 120.000; new = 60.000; churn = 60.000;
        // reactivation = 60.000; end = steady + new + winback = 180.000.
        $this->assertSame(120_000, $waterfall->startMrr->minor());
        $this->assertSame(60_000, $waterfall->new->minor());
        $this->assertSame(60_000, $waterfall->churn->minor());
        $this->assertSame(60_000, $waterfall->reactivation->minor());
        $this->assertSame(0, $waterfall->expansion->minor());
        $this->assertSame(180_000, $waterfall->endMrr->minor());

        // NRR = GRR = (120.000 − 60.000) / 120.000 = 50% = 5000 bps.
        $rates = $analytics->retention($start, $end, 'DKK');
        $this->assertNotNull($rates);
        $this->assertSame(5000, $rates->nrr->basisPoints());
        $this->assertSame(5000, $rates->grr->basisPoints());
    }

    public function test_cohort_matrix_groups_subscriptions_by_start_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $this->seed(CatalogSeeder::class);
        // Business (volume) at the helper's 5 seats = 60.000 minor per account.
        $business = Plan::query()->where('key', 'business')->firstOrFail();

        $this->account('org_may', $business, SubscriptionStatus::Active, created: '2026-05-04');
        $this->account('org_may2', $business, SubscriptionStatus::Active, created: '2026-05-20');
        $this->account('org_jul', $business, SubscriptionStatus::Active, created: '2026-07-02');

        $analytics = app(RevenueAnalytics::class);
        $matrix = $analytics->cohorts($analytics->monthLabels(6, Carbon::now()));

        $may = $matrix->rowFor('2026-05');
        $this->assertNotNull($may);
        $this->assertSame(2, $may->initialCount);
        $this->assertSame(120_000, $may->initialMrr->minor()); // 2 accounts × 5 seats × 12.000

        $jul = $matrix->rowFor('2026-07');
        $this->assertNotNull($jul);
        $this->assertSame(1, $jul->initialCount);
    }

    public function test_analytics_screens_render_on_real_data(): void
    {
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);

        $this->withSession($this->session)->get('/analytics/revenue')
            ->assertOk()
            ->assertSee('MRR movement')
            ->assertSee('ARR bridge');

        $this->withSession($this->session)->get('/analytics/retention')
            ->assertOk()
            ->assertSee('Net revenue retention')
            ->assertSee('Cohort retention');
    }

    public function test_seat_change_records_expansion_and_contraction_on_a_per_seat_plan(): void
    {
        $subscriber = app(SubscribesOrganizations::class);
        $depth = app(ManagesSubscriptionDepth::class);

        // A genuine per-seat plan: per_unit at 5.000 minor/seat in DKK.
        $plan = $this->perUnitPlan(5_000);

        // Subscribe at 5 seats (before the window): MRR = 5 × 5.000 = 25.000 — seats × price,
        // not the flat 5.000 base.
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));
        $subscription = $subscriber->subscribe($this->org('org_seats'), $plan, seats: 5);

        $analytics = app(RevenueAnalytics::class);
        $this->assertSame(25_000, $analytics->revenue()->lineFor('DKK')?->mrr->minor());

        // Grow 5 → 10 seats: an expansion of 25.000 (25.000 → 50.000) is recorded.
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));
        $depth->changeQuantity($subscription->refresh()->loadMissing('plan', 'organization'), 10);

        // The MRR read model now reflects 10 seats × 5.000 = 50.000 (not the flat base).
        $this->assertSame(50_000, $analytics->revenue()->lineFor('DKK')?->mrr->minor());

        // Shrink 10 → 5 seats: a contraction of 25.000 (50.000 → 25.000) is recorded.
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00'));
        $depth->changeQuantity($subscription->refresh()->loadMissing('plan', 'organization'), 5);

        $expansion = SubscriptionMrrMovement::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', SubscriptionMrrMovement::KIND_EXPANSION)
            ->firstOrFail();
        $this->assertSame(25_000, $expansion->previous_mrr_minor);
        $this->assertSame(50_000, $expansion->new_mrr_minor);

        $contraction = SubscriptionMrrMovement::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', SubscriptionMrrMovement::KIND_CONTRACTION)
            ->firstOrFail();
        $this->assertSame(50_000, $contraction->previous_mrr_minor);
        $this->assertSame(25_000, $contraction->new_mrr_minor);

        // The waterfall over the window holding only the 5 → 10 expansion reconciles.
        $waterfall = $analytics
            ->movement(Carbon::parse('2026-08-05 00:00:00'), Carbon::parse('2026-08-15 00:00:00'))
            ->waterfallFor('DKK');
        $this->assertNotNull($waterfall);
        $this->assertTrue($waterfall->reconciles());
        $this->assertSame(25_000, $waterfall->expansion->minor());
        $this->assertSame(25_000, $waterfall->startMrr->minor());
        $this->assertSame(50_000, $waterfall->endMrr->minor());
    }

    /** A minimal per-unit (per-seat) plan priced in DKK at `$unitMinor` per seat. */
    private function perUnitPlan(int $unitMinor): Plan
    {
        $product = Product::query()->create(['key' => 'seats-product', 'name' => 'Seats Product']);

        $plan = Plan::query()->create([
            'product_id' => $product->id,
            'key' => 'per-seat',
            'name' => 'Per seat',
            'interval' => 'month',
            'active' => true,
        ]);

        PlanPrice::query()->create([
            'plan_id' => $plan->id,
            'currency' => 'DKK',
            'price_minor' => $unitMinor,
            'pricing_model' => 'per_unit',
        ]);

        return $plan->load('prices.tiers');
    }

    private function org(string $id): Organization
    {
        return Organization::query()->updateOrCreate(
            ['id' => $id],
            ['name' => ucfirst($id), 'billing_currency' => 'DKK', 'billing_country' => 'DK'],
        );
    }

    private function account(string $orgId, Plan $plan, SubscriptionStatus $status, string $created, ?string $canceled = null): Subscription
    {
        Organization::query()->updateOrCreate(
            ['id' => $orgId],
            ['name' => ucfirst($orgId), 'billing_currency' => 'DKK', 'billing_country' => 'DK'],
        );

        $subscription = Subscription::query()->create([
            'organization_id' => $orgId,
            'plan_id' => $plan->id,
            'status' => $status,
            'seats' => 5,
            'current_period_start' => Carbon::parse($created),
            'current_period_end' => Carbon::parse($created)->addMonth(),
            'canceled_at' => $canceled !== null ? Carbon::parse($canceled) : null,
        ]);

        $subscription->forceFill(['created_at' => Carbon::parse($created)])->save();

        return $subscription;
    }
}
