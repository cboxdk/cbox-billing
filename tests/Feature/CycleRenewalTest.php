<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\CycleRenewalService;
use App\Billing\Subscriptions\ValueObjects\RenewalOutcome;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The scheduled cycle renewal (ADR-0012/0013/0014). Every case is time-travelled through
 * `Carbon::setTestNow`, so the renewal sees a real boundary and the idempotent, time-keyed
 * granting is exercised exactly as the daily cron would drive it.
 */
class CycleRenewalTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::query()->create(['key' => 'app', 'name' => 'App']);
    }

    public function test_a_monthly_allotment_is_granted_at_the_boundary_and_not_again_mid_cycle(): void
    {
        $this->freezeAt('2026-01-01');
        $plan = $this->monthlyPlan([$this->grant(pool: Pools::INCLUDED, amount: 50_000)]);
        $subscription = $this->subscription('org_month', $plan, '2026-01-01', '2026-02-01');

        // Opening cycle: the January slice is granted, nothing more.
        $this->renew($subscription);
        $this->assertSame(50_000, $this->balance('org_month', Pools::included()));
        $this->assertSame(1, $this->lotCount('org_month'));

        // Mid-cycle: the same slice is already in the wallet, so a re-run grants nothing.
        $this->freezeAt('2026-01-15');
        $outcome = $this->renew($subscription);
        $this->assertFalse($outcome->baseRenewed);
        $this->assertSame(50_000, $this->balance('org_month', Pools::included()));
        $this->assertSame(1, $this->lotCount('org_month'));

        // Boundary: the period rolls over and the February slice is granted (the January
        // EndOfPeriod lot has reset, so the balance is the new cycle's allotment, not both).
        $this->freezeAt('2026-02-01');
        $outcome = $this->renew($subscription);
        $this->assertTrue($outcome->baseRenewed);
        $this->assertSame(50_000, $this->balance('org_month', Pools::included()));
        $this->assertSame(2, $this->lotCount('org_month'));
        $this->assertSame('2026-03-01', $subscription->refresh()->current_period_end?->format('Y-m-d'));
    }

    public function test_a_boundary_rerun_is_idempotent_and_issues_no_duplicate_invoice(): void
    {
        $this->freezeAt('2026-01-01');
        $plan = $this->monthlyPlan([$this->grant(pool: Pools::INCLUDED, amount: 50_000)]);
        $subscription = $this->subscription('org_idem', $plan, '2026-01-01', '2026-02-01');

        $this->renew($subscription);

        // First boundary run: advance + one invoice.
        $this->freezeAt('2026-02-01');
        $first = $this->renew($subscription);
        $this->assertTrue($first->baseRenewed);
        $this->assertNotNull($first->invoice);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(2, $this->lotCount('org_idem'));

        // Second run at the same instant: the period has already advanced, so nothing is
        // due — no new lot, no second invoice.
        $second = $this->renew($subscription);
        $this->assertFalse($second->baseRenewed);
        $this->assertNull($second->invoice);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(2, $this->lotCount('org_idem'));
    }

    public function test_an_end_of_period_allotment_resets_while_a_duration_allotment_rolls_over(): void
    {
        $this->freezeAt('2026-01-01');
        $plan = $this->monthlyPlan([
            // Resets each month (included pool, EndOfPeriod).
            $this->grant(pool: Pools::INCLUDED, amount: 10_000),
            // Rolls over: a Duration far beyond the cycle keeps each month's lot alive.
            $this->grant(pool: Pools::PROMOTIONAL, amount: 5_000, rolloverSeconds: 10 * 365 * 24 * 3_600),
        ]);
        $subscription = $this->subscription('org_roll', $plan, '2026-01-01', '2026-02-01');

        $this->renew($subscription);
        $this->assertSame(10_000, $this->balance('org_roll', Pools::included()));
        $this->assertSame(5_000, $this->balance('org_roll', Pools::promotional()));

        // Second cycle: the reset pool is back to one month's allotment; the rollover pool
        // has accumulated both months.
        $this->freezeAt('2026-02-01');
        $this->renew($subscription);
        $this->assertSame(10_000, $this->balance('org_roll', Pools::included()));
        $this->assertSame(10_000, $this->balance('org_roll', Pools::promotional()));

        // Third cycle: reset stays flat, rollover keeps accumulating.
        $this->freezeAt('2026-03-01');
        $this->renew($subscription);
        $this->assertSame(10_000, $this->balance('org_roll', Pools::included()));
        $this->assertSame(15_000, $this->balance('org_roll', Pools::promotional()));
    }

    public function test_a_distributed_yearly_total_drips_on_the_finer_cadence(): void
    {
        $this->freezeAt('2026-01-01');
        // A yearly plan whose 1,200,000 annual allotment is distributed across the twelve
        // monthly slices of the period — 100,000 a month.
        $plan = $this->yearlyPlan([
            $this->grant(pool: Pools::INCLUDED, amount: 1_200_000, cadence: 'monthly', mode: 'distributed'),
        ]);
        $subscription = $this->subscription('org_drip', $plan, '2026-01-01', '2027-01-01');

        // January: exactly one monthly slice of 100,000 has vested (the distributed share,
        // not the whole 1,200,000 total); the yearly base has NOT rolled over.
        $jan = $this->renew($subscription);
        $this->assertFalse($jan->baseRenewed);
        $this->assertSame(1, $this->lotCount('org_drip'));
        $this->assertSame(100_000, $this->balance('org_drip', Pools::included()));

        // February: a second slice drips — a new lot within the same yearly period, the
        // active balance still one month's 100,000 share.
        $this->freezeAt('2026-02-01');
        $feb = $this->renew($subscription);
        $this->assertFalse($feb->baseRenewed);
        $this->assertSame(2, $this->lotCount('org_drip'));
        $this->assertSame(100_000, $this->balance('org_drip', Pools::included()));

        // March: a third slice. Each month contributes exactly one 100,000 slice.
        $this->freezeAt('2026-03-01');
        $this->renew($subscription);
        $this->assertSame(3, $this->lotCount('org_drip'));
        $this->assertSame(100_000, $this->balance('org_drip', Pools::included()));
        $this->assertSame('2027-01-01', $subscription->refresh()->current_period_end?->format('Y-m-d'));
    }

    public function test_an_aligned_add_on_renews_with_the_base_while_an_independent_one_runs_on_its_own_cycle(): void
    {
        $this->freezeAt('2026-01-01');
        // A yearly base so its boundary is a year away, making the monthly independent
        // add-on's own cadence visible against it.
        $plan = $this->yearlyPlan([]);
        $subscription = $this->subscription('org_addon', $plan, '2026-01-01', '2027-01-01');

        $aligned = SubscriptionAddOn::query()->create([
            'subscription_id' => $subscription->id,
            'key' => 'pack',
            'price_minor' => 5_000,
            'currency' => 'DKK',
            'alignment' => AddOnAlignment::Aligned,
            'credit_allotment' => 2_000,
        ]);

        SubscriptionAddOn::query()->create([
            'subscription_id' => $subscription->id,
            'key' => 'sms',
            'price_minor' => 1_000,
            'currency' => 'DKK',
            'alignment' => AddOnAlignment::Independent,
            'credit_allotment' => 1_000,
            'anchor_day' => 1,
            'anchor_month' => 1,
            'interval' => BillingInterval::Monthly,
        ]);

        // One month on: the independent (monthly) add-on renews on its own boundary; the
        // aligned add-on and the yearly base do not.
        $this->freezeAt('2026-02-01');
        $outcome = $this->renew($subscription->fresh());
        $this->assertFalse($outcome->baseRenewed);
        $this->assertSame(1, $outcome->addOnsRenewed);
        $this->assertTrue($this->lotExists('org_addon', 'org_addon:addon:sms:'.$this->ms('2026-02-01')));
        $this->assertFalse($this->lotExists('org_addon', 'org_addon:addon:pack:'.$this->ms('2026-02-01')));

        // A year on: the base rolls over and the aligned add-on renews with it.
        $this->freezeAt('2027-01-01');
        $outcome = $this->renew($subscription->fresh());
        $this->assertTrue($outcome->baseRenewed);
        $this->assertTrue($this->lotExists('org_addon', 'org_addon:addon:pack:'.$this->ms('2027-01-01')));
        $this->assertSame(2_000, (int) $aligned->refresh()->credit_allotment);
    }

    public function test_the_renew_command_advances_due_subscriptions_and_skips_paused_ones(): void
    {
        $this->freezeAt('2026-01-01');
        $plan = $this->monthlyPlan([$this->grant(pool: Pools::INCLUDED, amount: 50_000)]);
        $due = $this->subscription('org_cmd', $plan, '2026-01-01', '2026-02-01');
        $paused = $this->subscription('org_cmd_paused', $plan, '2026-01-01', '2026-02-01', [
            'paused_at' => Carbon::parse('2026-01-05', 'UTC'),
        ]);

        $this->freezeAt('2026-02-01');
        $this->assertSame(0, Artisan::call('billing:renew'));

        // The active subscription rolled over and was invoiced; the paused one was left.
        $this->assertSame('2026-03-01', $due->refresh()->current_period_end?->format('Y-m-d'));
        $this->assertSame('2026-02-01', $paused->refresh()->current_period_end?->format('Y-m-d'));
        $this->assertSame(50_000, $this->balance('org_cmd', Pools::included()));
        $this->assertSame(0, $this->lotCount('org_cmd_paused'));
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_paused_subscription_is_skipped(): void
    {
        $this->freezeAt('2026-01-01');
        $plan = $this->monthlyPlan([$this->grant(pool: Pools::INCLUDED, amount: 50_000)]);
        $subscription = $this->subscription('org_paused', $plan, '2026-01-01', '2026-02-01', [
            'paused_at' => Carbon::parse('2026-01-10', 'UTC'),
        ]);

        $this->freezeAt('2026-02-01');
        $outcome = $this->renew($subscription);

        $this->assertTrue($outcome->skipped);
        $this->assertSame(0, $this->lotCount('org_paused'));
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('2026-02-01', $subscription->refresh()->current_period_end?->format('Y-m-d'));
    }

    // --- fixtures ------------------------------------------------------------------------

    private function renew(Subscription $subscription): RenewalOutcome
    {
        return app(CycleRenewalService::class)->renew($subscription);
    }

    /**
     * @param  list<array<string, mixed>>  $grants
     */
    private function monthlyPlan(array $grants): Plan
    {
        return $this->plan('month', $grants);
    }

    /**
     * @param  list<array<string, mixed>>  $grants
     */
    private function yearlyPlan(array $grants): Plan
    {
        return $this->plan('year', $grants);
    }

    /**
     * @param  list<array<string, mixed>>  $grants
     */
    private function plan(string $interval, array $grants): Plan
    {
        $plan = Plan::query()->create([
            'product_id' => $this->product->id,
            'key' => 'plan_'.uniqid(),
            'name' => 'Plan',
            'interval' => $interval,
            'active' => true,
        ]);

        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => 100_000]);

        foreach ($grants as $grant) {
            PlanCreditGrant::query()->create(['plan_id' => $plan->id] + $grant);
        }

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function grant(string $pool, int $amount, string $cadence = 'monthly', string $mode = 'fixed', ?int $rolloverSeconds = null): array
    {
        return [
            'pool' => $pool,
            'kind' => 'base',
            'cadence' => $cadence,
            'amount' => $amount,
            'amount_mode' => $mode,
            'rollover_seconds' => $rolloverSeconds,
            'denomination' => 'credit',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(string $org, Plan $plan, string $start, string $end, array $overrides = []): Subscription
    {
        Organization::query()->create(['id' => $org, 'name' => $org, 'billing_country' => 'DK']);

        return Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse($start, 'UTC'),
            'current_period_end' => Carbon::parse($end, 'UTC'),
            'cancel_at_period_end' => false,
        ] + $overrides);
    }

    private function balance(string $org, Pool $pool): int
    {
        return app(Wallet::class)->balance($org, $pool, Denomination::unit('credit'), $this->nowMs());
    }

    private function lotCount(string $org): int
    {
        return (int) \DB::table('billing_wallet_lots')->where('org', $org)->count();
    }

    private function lotExists(string $org, string $grantId): bool
    {
        return \DB::table('billing_wallet_lots')->where('org', $org)->where('grant_id', $grantId)->exists();
    }

    private function freezeAt(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 00:00:00', 'UTC'));
    }

    private function ms(string $date): int
    {
        return Carbon::parse($date.' 00:00:00', 'UTC')->getTimestamp() * 1000;
    }

    private function nowMs(): int
    {
        return Carbon::now()->getTimestamp() * 1000;
    }
}
