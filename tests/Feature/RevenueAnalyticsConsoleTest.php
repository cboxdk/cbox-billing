<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\RevenueAnalytics;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
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

    public function test_mrr_movement_waterfall_reads_recorded_plan_changes(): void
    {
        $subscriber = app(SubscribesOrganizations::class);

        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();
        $scale = Plan::query()->where('key', 'scale')->firstOrFail();
        $starter = Plan::query()->where('key', 'starter')->firstOrFail();

        // Subscribe before the window (these "new" movements fall outside it)…
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00:00'));
        $up = $subscriber->subscribe($this->org('org_up'), $team);
        $down = $subscriber->subscribe($this->org('org_down'), $scale);

        // …then a real upgrade (expansion) and downgrade (contraction) inside it.
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
        $subscriber->changePlan($up->refresh()->loadMissing('plan', 'organization'), $scale);
        $subscriber->changePlan($down->refresh()->loadMissing('plan', 'organization'), $starter);

        $waterfall = app(RevenueAnalytics::class)
            ->movement(Carbon::parse('2026-07-23 00:00:00'), Carbon::parse('2026-07-28 23:59:59'))
            ->waterfallFor('DKK');

        $this->assertNotNull($waterfall);
        $this->assertTrue($waterfall->reconciles());

        // Team 124.000 → Scale 990.000 = +866.000 expansion; Scale 990.000 → Starter
        // 29.000 = −961.000 contraction. The subscribe "new" rows are outside the window.
        $this->assertSame(866_000, $waterfall->expansion->minor());
        $this->assertSame(961_000, $waterfall->contraction->minor());
        $this->assertSame(0, $waterfall->new->minor());
        $this->assertSame(1_114_000, $waterfall->startMrr->minor()); // 124.000 + 990.000
        $this->assertSame(1_019_000, $waterfall->endMrr->minor());   // 990.000 + 29.000
    }

    public function test_mrr_movement_classifies_new_churn_reactivation_and_retention(): void
    {
        $subscriber = app(SubscribesOrganizations::class);
        $depth = app(ManagesSubscriptionDepth::class);

        $this->seed(CatalogSeeder::class);
        $team = Plan::query()->where('key', 'team')->firstOrFail();

        // Before the window: a steady account, a to-be-churned account, and an account that
        // pauses (its pause churn lands outside the window).
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $subscriber->subscribe($this->org('org_steady'), $team);
        $churn = $subscriber->subscribe($this->org('org_churn'), $team);
        $winback = $subscriber->subscribe($this->org('org_winback'), $team);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $depth->pause($winback->refresh()->loadMissing('plan', 'organization'));

        // Inside the window: a new logo, a churn, and a win-back (resume from pause).
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));
        $subscriber->subscribe($this->org('org_new'), $team);
        $subscriber->cancel($churn->refresh()->loadMissing('plan', 'organization'), atPeriodEnd: false);
        $depth->resume($winback->refresh()->loadMissing('plan', 'organization'));

        $analytics = app(RevenueAnalytics::class);
        $start = Carbon::parse('2026-07-01 00:00:00');
        $end = Carbon::parse('2026-07-15 00:00:00');

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
