<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Retirement\PlanRetirementService;
use App\Mail\PlanRetiringMail;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The plan-sunset flow (ADR-0016). Every case time-travels through `Carbon::setTestNow` so
 * the retirement resolver sees a real cutoff + renewal boundary. It drives the four
 * migration verdicts (default / chosen successor / chosen cancel / unresolved), the sunset
 * notice a subscriber sees, and the ahead-of-cutoff reminder — each against the seeded
 * Early Access plan being retired onto the plan ladder.
 */
class PlanRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_no_choice_with_a_default_migrates_to_the_default_at_renewal(): void
    {
        $subscription = $this->earlyAccessSubscription('org_default');
        $this->markRetiring('early-access', '2026-02-01', successorKey: 'team');

        $this->freezeAt('2026-02-01');
        $outcome = $this->retirements()->migrate($subscription->fresh());

        $this->assertSame('resolved-to-default', $outcome);
        $this->assertSame($this->planId('team'), $subscription->fresh()->plan_id);
        $this->assertDatabaseHas('plan_retirement_events', [
            'subscription_id' => $subscription->id,
            'type' => 'migrated',
            'outcome' => 'resolved-to-default',
            'successor_plan_id' => $this->planId('team'),
        ]);
        // The migrated subscription renews on the successor's price, not the retired plan's.
        $this->assertSame($this->planId('team'), $subscription->fresh()->plan_id);
        $this->assertGreaterThan(0, Invoice::query()->where('organization_id', 'org_default')->count());
    }

    public function test_a_chosen_successor_migrates_to_that_successor(): void
    {
        $subscription = $this->earlyAccessSubscription('org_succ');
        $this->markRetiring('early-access', '2026-02-01', successorKey: 'team');

        // The subscriber elects Business — a different plan than the default (Team).
        $this->retirements()->electSuccessor($subscription, $this->plan('business'));

        $this->freezeAt('2026-02-01');
        $outcome = $this->retirements()->migrate($subscription->fresh());

        $this->assertSame('resolved-to-successor', $outcome);
        $this->assertSame($this->planId('business'), $subscription->fresh()->plan_id);
        $this->assertDatabaseHas('plan_retirement_events', [
            'subscription_id' => $subscription->id,
            'type' => 'migrated',
            'outcome' => 'resolved-to-successor',
            'successor_plan_id' => $this->planId('business'),
        ]);
    }

    public function test_a_chosen_cancel_cancels_at_renewal(): void
    {
        $subscription = $this->earlyAccessSubscription('org_cancel');
        $this->markRetiring('early-access', '2026-02-01', successorKey: 'team');

        // The subscriber elects to cancel instead.
        $subscription->forceFill(['cancel_at_period_end' => true])->save();

        $this->freezeAt('2026-02-01');
        $outcome = $this->retirements()->migrate($subscription->fresh());

        $this->assertSame('resolved-to-cancel', $outcome);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertDatabaseHas('plan_retirement_events', [
            'subscription_id' => $subscription->id,
            'type' => 'migrated',
            'outcome' => 'resolved-to-cancel',
        ]);
    }

    public function test_no_choice_and_no_default_is_flagged_unresolved_and_not_charged(): void
    {
        $subscription = $this->earlyAccessSubscription('org_unres');
        $this->markRetiring('early-access', '2026-02-01', successorKey: null);

        $this->freezeAt('2026-02-01');
        $outcome = $this->retirements()->migrate($subscription->fresh());

        $this->assertSame('unresolved-retirement', $outcome);
        // Deny-by-default: still on the retired plan, and never charged.
        $this->assertSame($this->planId('early-access'), $subscription->fresh()->plan_id);
        $this->assertSame(0, Invoice::query()->where('organization_id', 'org_unres')->count());
        $this->assertDatabaseHas('plan_retirement_events', [
            'subscription_id' => $subscription->id,
            'type' => 'unresolved',
            'outcome' => 'unresolved-retirement',
        ]);
    }

    public function test_the_migration_command_migrates_due_subscriptions_idempotently(): void
    {
        $subscription = $this->earlyAccessSubscription('org_cmd');
        $this->markRetiring('early-access', '2026-02-01', successorKey: 'team');

        $this->freezeAt('2026-02-01');
        $this->assertSame(0, Artisan::call('billing:migrate-retiring-plans'));
        $this->assertSame($this->planId('team'), $subscription->fresh()->plan_id);

        // A second run re-migrates nothing (recorded per retirement window).
        Artisan::call('billing:migrate-retiring-plans');
        $this->assertSame($this->planId('team'), $subscription->fresh()->plan_id);
        $this->assertSame(1, \DB::table('plan_retirement_events')
            ->where('subscription_id', $subscription->id)
            ->where('type', 'migrated')
            ->count());
    }

    public function test_the_sunset_notice_carries_the_cutoff_renewal_due_and_default(): void
    {
        $subscription = $this->earlyAccessSubscription('org_notice');
        $this->markRetiring('early-access', '2026-01-15', successorKey: 'team');

        // Retired, but the renewal is not yet due — the subscriber must choose by the boundary.
        $this->freezeAt('2026-01-20');
        $notice = $this->retirements()->noticeFor($subscription->fresh());

        $this->assertNotNull($notice);
        $this->assertSame('Early Access', $notice->planName);
        $this->assertSame('15 Jan 2026', $notice->retiresAt);
        $this->assertSame('1 Feb 2026', $notice->renewalDue);
        $this->assertSame('none', $notice->election);
        $this->assertTrue($notice->hasDefault());
        $this->assertSame('Team', $notice->defaultSuccessorName);
        $this->assertNotEmpty($notice->successors);
    }

    public function test_the_portal_renders_the_sunset_notice_with_the_three_choices(): void
    {
        $this->earlyAccessSubscription('org_portal');
        $this->markRetiring('early-access', '2026-01-15', successorKey: 'team');
        $this->freezeAt('2026-01-20');

        $session = $this->portalSession('org_portal');

        $this->get('/billing/portal/'.$session->token)
            ->assertOk()
            ->assertSee('Early Access is being retired')
            ->assertSee('15 Jan 2026')
            ->assertSee('1 Feb 2026')
            ->assertSee('choose your new plan by then')
            ->assertSee('Switch at renewal')   // choice 1
            ->assertSee('Cancel at period end') // choice 2
            ->assertSee('Do nothing')           // choice 3
            ->assertSee('Team');                // the default it names
    }

    public function test_the_reminder_is_queued_once_per_subscription_per_window(): void
    {
        Mail::fake();

        $this->earlyAccessSubscription('org_remind');
        $this->markRetiring('early-access', '2026-02-01', successorKey: 'team');

        // Within the 14-day lead window ahead of the 2026-02-01 cutoff.
        $this->freezeAt('2026-01-25');

        $this->assertSame(1, $this->retirements()->remindAffected(14));
        Mail::assertQueued(PlanRetiringMail::class, 1);

        // A second run in the same window queues nothing (idempotent).
        $this->assertSame(0, $this->retirements()->remindAffected(14));
        Mail::assertQueued(PlanRetiringMail::class, 1);
    }

    // --- fixtures ------------------------------------------------------------------------

    private function retirements(): PlanRetirementService
    {
        return app(PlanRetirementService::class);
    }

    private function earlyAccessSubscription(string $org, string $start = '2026-01-01', string $end = '2026-02-01'): Subscription
    {
        Organization::query()->create([
            'id' => $org,
            'name' => ucfirst($org),
            'billing_email' => $org.'@example.test',
            'billing_currency' => 'DKK',
            'billing_country' => 'DK',
        ]);

        return Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $this->planId('early-access'),
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse($start, 'UTC'),
            'current_period_end' => Carbon::parse($end, 'UTC'),
            'cancel_at_period_end' => false,
        ]);
    }

    private function markRetiring(string $planKey, string $retiresAt, ?string $successorKey): Plan
    {
        $plan = $this->plan($planKey);

        $plan->forceFill([
            'retires_at' => Carbon::parse($retiresAt, 'UTC'),
            'default_successor_plan_id' => $successorKey !== null ? $this->planId($successorKey) : null,
        ])->save();

        return $plan;
    }

    private function plan(string $key): Plan
    {
        return Plan::query()->where('key', $key)->firstOrFail();
    }

    private function planId(string $key): int
    {
        return (int) Plan::query()->where('key', $key)->value('id');
    }

    private function portalSession(string $org): BillingSession
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail();
    }

    private function freezeAt(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 00:00:00', 'UTC'));
    }
}
