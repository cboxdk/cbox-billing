<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\RevenueAnalytics;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
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

        // Contributing subscriptions (engine state→MRR policy): Hverdag team 124.000 +
        // Klarhed starter 29.000 + Fjord scale 990.000 + Nordwind (past-due) business
        // 349.000 + Vinter (non-renewing) team 124.000 = 1.616.000 minor. Trialing (Aula),
        // paused (Meridian) and canceled (Söder) contribute nothing.
        $this->assertNotNull($line);
        $this->assertSame(1_616_000, $line->mrr->minor());
        $this->assertSame(1_616_000 * 12, $line->arr->minor());
        $this->assertSame(5, $line->subscriptions);
    }

    public function test_mrr_movement_and_retention_are_decomposed_by_the_engine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $this->seed(CatalogSeeder::class);

        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // A controlled book: one steady account, one new logo, one churn, one win-back —
        // so the engine waterfall has a known new / churn / reactivation decomposition.
        $this->account('org_steady', $team, SubscriptionStatus::Active, created: '2026-01-01');
        $this->account('org_new', $team, SubscriptionStatus::Active, created: '2026-07-10');
        $this->account('org_churn', $team, SubscriptionStatus::Canceled, created: '2025-02-01', canceled: '2026-07-05');

        $winback = $this->account('org_winback', $team, SubscriptionStatus::Active, created: '2026-07-08');
        SubscriptionCancellation::query()->create([
            'subscription_id' => $winback->id,
            'organization_id' => 'org_winback',
            'plan_id' => $team->id,
            'mode' => SubscriptionCancellation::MODE_REACTIVATE,
        ]);

        $analytics = app(RevenueAnalytics::class);
        $end = Carbon::now();
        $start = $end->copy()->subMonthNoOverflow(1);

        $waterfall = $analytics->movement($start, $end)->waterfallFor('DKK');
        $this->assertNotNull($waterfall);
        $this->assertTrue($waterfall->reconciles());

        // start = steady + churn = 248.000; new = 124.000; churn = 124.000;
        // reactivation = 124.000; end = steady + new + winback = 372.000.
        $this->assertSame(248_000, $waterfall->startMrr->minor());
        $this->assertSame(124_000, $waterfall->new->minor());
        $this->assertSame(124_000, $waterfall->churn->minor());
        $this->assertSame(124_000, $waterfall->reactivation->minor());
        $this->assertSame(0, $waterfall->expansion->minor());
        $this->assertSame(372_000, $waterfall->endMrr->minor());

        // NRR = GRR = (248.000 − 124.000) / 248.000 = 50% = 5000 bps.
        $rates = $analytics->retention($start, $end, 'DKK');
        $this->assertNotNull($rates);
        $this->assertSame(5000, $rates->nrr->basisPoints());
        $this->assertSame(5000, $rates->grr->basisPoints());
    }

    public function test_cohort_matrix_groups_subscriptions_by_start_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        $this->account('org_may', $team, SubscriptionStatus::Active, created: '2026-05-04');
        $this->account('org_may2', $team, SubscriptionStatus::Active, created: '2026-05-20');
        $this->account('org_jul', $team, SubscriptionStatus::Active, created: '2026-07-02');

        $analytics = app(RevenueAnalytics::class);
        $matrix = $analytics->cohorts($analytics->monthLabels(6, Carbon::now()));

        $may = $matrix->rowFor('2026-05');
        $this->assertNotNull($may);
        $this->assertSame(2, $may->initialCount);
        $this->assertSame(248_000, $may->initialMrr->minor());

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
