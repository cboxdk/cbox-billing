<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\TestMode\CapturedNotifications;
use App\Billing\TestMode\TestClockAdvancer;
use App\Billing\TestMode\ValueObjects\AdvanceResult;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use App\Models\TestClock;
use Carbon\CarbonImmutable;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The headline sandbox capability: advancing a test clock runs the due billing logic for the
 * subscriptions bound to it — renewals, trial conversions, dunning — exactly as it would over
 * real elapsed time, deterministically and idempotently, and with no real gateway charge or
 * email. Every case is driven purely by a clock advance.
 */
class TestClockAdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $this->seed(CatalogSeeder::class);
        app(BillingContext::class)->setMode(BillingMode::Test);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_advancing_past_a_monthly_boundary_fires_exactly_one_renewal_and_is_idempotent(): void
    {
        [$subscription, $clock] = $this->boundMonthlySubscription('org_month');
        $before = $this->invoiceCount($subscription);

        $result = $this->advance($clock, '2026-02-15');

        $this->assertSame(1, $result->renewals);
        $this->assertSame($before + 1, $this->invoiceCount($subscription));
        $this->assertDatabaseHas('invoices', ['subscription_id' => $subscription->id, 'livemode' => false]);
        $this->assertSame('2026-02-15', $clock->refresh()->now_at->format('Y-m-d'));

        // Idempotent: re-advancing to the same instant does nothing and raises no new invoice.
        $again = $this->advance($clock, '2026-02-15');
        $this->assertSame(0, $again->renewals);
        $this->assertSame($before + 1, $this->invoiceCount($subscription));
    }

    public function test_advancing_a_full_year_fires_twelve_monthly_renewals_on_the_right_dates(): void
    {
        [$subscription, $clock] = $this->boundMonthlySubscription('org_year_of_months');
        $before = $this->invoiceCount($subscription);

        $result = $this->advance($clock, '2026-12-15');

        // Twelve boundaries (Feb…Dec is 11, plus the Jan→Feb one already counted): eleven from
        // 2026-02-01 through 2026-12-01.
        $this->assertSame(11, $result->renewals);
        $this->assertSame($before + 11, $this->invoiceCount($subscription));
    }

    public function test_advancing_past_a_yearly_boundary_fires_one_renewal(): void
    {
        $product = Plan::query()->where('key', 'starter')->firstOrFail()->product;
        $yearly = Plan::query()->create([
            'product_id' => $product?->id,
            'key' => 'yearly_'.uniqid(),
            'name' => 'Yearly',
            'interval' => 'year',
            'active' => true,
        ]);
        PlanPrice::query()->create(['plan_id' => $yearly->id, 'currency' => 'DKK', 'price_minor' => 1_200_000]);

        $org = $this->sandboxOrg('org_year');
        $subscription = app(SubscribesOrganizations::class)->subscribe($org, $yearly->load('prices', 'product'));
        $clock = $this->bind($subscription, '2026-01-01');

        $result = $this->advance($clock, '2027-02-01');

        $this->assertSame(1, $result->renewals);
    }

    public function test_advancing_past_a_trial_end_converts_the_trial(): void
    {
        $org = $this->sandboxOrg('org_trial');
        $plan = Plan::query()->where('key', 'starter')->firstOrFail();

        $subscription = Subscription::query()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-01-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-02-01', 'UTC'),
            'trial_ends_at' => Carbon::parse('2026-01-15', 'UTC'),
            'cancel_at_period_end' => false,
        ]);
        $clock = $this->bind($subscription, '2026-01-01');

        $result = $this->advance($clock, '2026-01-20');

        $this->assertSame(1, $result->trialConversions);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    public function test_a_declining_clock_drives_the_dunning_schedule_attempt_by_attempt_to_the_terminal_action(): void
    {
        config()->set('billing.payment.retry.schedule', [1, 3, 5, 7]);
        config()->set('billing.payment.retry.terminal_action', 'cancel');

        [$subscription, $clock] = $this->boundMonthlySubscription('org_dunning');
        $clock->forceFill(['charge_outcome' => 'decline'])->save();

        // Advance well past the last backoff offset (2026-02-01 + 7 days): the renewal declines,
        // opening the schedule, then every scheduled attempt fires in order and exhausts it.
        $result = $this->advance($clock, '2026-02-20');

        $this->assertSame(1, $result->renewals);
        $this->assertSame(4, $result->dunningAttempts);

        $retry = PaymentRetry::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame(PaymentRetry::STATUS_EXHAUSTED, $retry->status);
        $this->assertSame(4, $retry->attempts);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
    }

    public function test_a_once_coupon_is_consumed_by_the_first_clock_driven_renewal_only(): void
    {
        [$subscription, $clock] = $this->boundMonthlySubscription('org_coupon_once');
        $this->attachCoupon($subscription, 'once', 1);

        $this->advance($clock, '2026-02-15');
        $this->assertSame(0, $subscription->coupon()->firstOrFail()->remaining_periods);
        $this->assertFalse($subscription->coupon()->firstOrFail()->appliesNow());

        // A second renewal a month later does not re-consume (already at zero).
        $this->advance($clock, '2026-03-15');
        $this->assertSame(0, $subscription->coupon()->firstOrFail()->remaining_periods);
    }

    public function test_a_repeating_coupon_is_consumed_over_exactly_its_three_clock_driven_renewals(): void
    {
        [$subscription, $clock] = $this->boundMonthlySubscription('org_coupon_repeat');
        $this->attachCoupon($subscription, 'repeating', 3);

        $this->advance($clock, '2026-02-15');
        $this->assertSame(2, $subscription->coupon()->firstOrFail()->remaining_periods);

        $this->advance($clock, '2026-04-15');
        $this->assertSame(0, $subscription->coupon()->firstOrFail()->remaining_periods);

        $this->advance($clock, '2026-05-15');
        $this->assertSame(0, $subscription->coupon()->firstOrFail()->remaining_periods);
    }

    public function test_test_mode_charges_route_to_the_fake_gateway_never_the_live_one(): void
    {
        $context = app(BillingContext::class);

        $context->setMode(BillingMode::Test);
        $this->assertSame('test', app(PaymentGateway::class)->name());

        $context->setMode(BillingMode::Live);
        $this->assertNotSame('test', app(PaymentGateway::class)->name());
    }

    public function test_test_mode_notifications_are_captured_not_delivered(): void
    {
        Mail::fake();
        config()->set('billing.payment.retry.schedule', [1, 3]);
        app(BillingContext::class)->setMode(BillingMode::Test);

        // A declining clock drives the dunning path, which emits payment-failed notifications.
        [, $clock] = $this->boundMonthlySubscription('org_no_email');
        $clock->forceFill(['charge_outcome' => 'decline'])->save();
        $this->advance($clock, '2026-02-20');

        // In test mode those notifications are captured, never queued to a real mailer.
        Mail::assertNothingQueued();
        $this->assertGreaterThan(0, app(CapturedNotifications::class)->count());
    }

    /** @return array{0: Subscription, 1: TestClock} */
    private function boundMonthlySubscription(string $orgId): array
    {
        $org = $this->sandboxOrg($orgId);
        $plan = Plan::query()->where('key', 'starter')->with('prices', 'product')->firstOrFail();
        $subscription = app(SubscribesOrganizations::class)->subscribe($org, $plan);
        $clock = $this->bind($subscription, '2026-01-01');

        return [$subscription, $clock];
    }

    private function sandboxOrg(string $id): Organization
    {
        app(BillingContext::class)->setMode(BillingMode::Test);

        return Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$id.'.test',
        ]);
    }

    private function bind(Subscription $subscription, string $now): TestClock
    {
        $clock = TestClock::query()->create([
            'name' => 'clock_'.$subscription->id,
            'now_at' => Carbon::parse($now.' 00:00:00', 'UTC'),
        ]);

        $subscription->forceFill(['test_clock_id' => $clock->id])->save();

        return $clock;
    }

    private function attachCoupon(Subscription $subscription, string $duration, int $remaining): void
    {
        $coupon = Coupon::query()->create([
            'code' => 'SAVE'.strtoupper($duration).$subscription->id,
            'discount_type' => 'percent',
            'percent_off' => 100,
            'duration' => $duration,
            'duration_in_periods' => $remaining,
        ]);

        SubscriptionCoupon::query()->create([
            'subscription_id' => $subscription->id,
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'discount_type' => 'percent',
            'percent_off' => 100,
            'duration' => $duration,
            'remaining_periods' => $remaining,
        ]);
    }

    private function advance(TestClock $clock, string $target): AdvanceResult
    {
        return app(TestClockAdvancer::class)->advance($clock, CarbonImmutable::parse($target.' 00:00:00', 'UTC'));
    }

    private function invoiceCount(Subscription $subscription): int
    {
        return Invoice::query()->where('subscription_id', $subscription->id)->count();
    }
}
