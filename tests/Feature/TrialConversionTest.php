<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Subscriptions\Contracts\ConvertsTrials;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Jobs\ConvertTrialJob;
use App\Mail\TrialEndingMail;
use App\Models\ApiToken;
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
 * Free trials (Part 3): a subscribe-with-trial opens `Trialing` and charges nothing; the
 * scheduled convert pass converts a due trial to paying `Active` with the first charge; a
 * trial-ending reminder goes out ahead of conversion; and a due trial with no payment method
 * (when one is required) takes the configured no-payment-method action.
 */
class TrialConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_subscribe_with_trial_opens_trialing_and_charges_nothing(): void
    {
        $auth = $this->orgWithToken('org_trial');

        $this->postJson('/api/v1/subscriptions', [
            'org' => 'org_trial',
            'plan' => 'starter',
            'trial' => true,
        ], $auth)
            ->assertCreated()
            ->assertJsonPath('subscription.status', 'trialing')
            ->assertJsonPath('subscription.plan', 'starter');

        $subscription = Subscription::query()->where('organization_id', 'org_trial')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);

        // Trialing serves the plan but charges nothing — no invoice is raised.
        $this->assertSame(0, Invoice::query()->where('organization_id', 'org_trial')->count());
    }

    public function test_convert_trials_command_converts_a_due_trial_with_the_first_charge(): void
    {
        $organization = $this->makeOrg('org_convert');
        $subscription = app(SubscribesOrganizations::class)->subscribeWithTrial($organization, $this->plan('starter'), trialDays: 7);

        $this->assertSame(0, Invoice::query()->where('organization_id', 'org_convert')->count());

        // Move past the trial end and run the convert pass.
        Carbon::setTestNow(Carbon::now()->addDays(8));
        Artisan::call('billing:convert-trials');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->trial_ends_at);

        // Conversion raised the first invoice (its collection follows the ordinary path).
        $this->assertSame(1, Invoice::query()->where('organization_id', 'org_convert')->count());

        Carbon::setTestNow();
    }

    public function test_trial_ending_reminder_is_emailed_ahead_of_conversion(): void
    {
        Mail::fake();
        $organization = $this->makeOrg('org_remind');
        $subscription = app(SubscribesOrganizations::class)->subscribeWithTrial($organization, $this->plan('starter'), trialDays: 14);

        // Trial ends exactly at the lead window (3 days) → the reminder fires once, and the
        // trial is not yet due so it is not converted.
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->addDays(3)])->save();

        (new ConvertTrialJob($subscription->id))->handle(
            app(ConvertsTrials::class),
            app(NotifiesCustomers::class),
            app('config'),
        );

        Mail::assertQueued(TrialEndingMail::class, fn (TrialEndingMail $m): bool => $m->hasTo('billing@org_remind.test') && $m->planName === 'Starter');
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->refresh()->status);
    }

    public function test_no_reminder_when_the_trial_end_is_far_off(): void
    {
        Mail::fake();
        $organization = $this->makeOrg('org_far_trial');
        $subscription = app(SubscribesOrganizations::class)->subscribeWithTrial($organization, $this->plan('starter'), trialDays: 20);

        (new ConvertTrialJob($subscription->id))->handle(
            app(ConvertsTrials::class),
            app(NotifiesCustomers::class),
            app('config'),
        );

        Mail::assertNotQueued(TrialEndingMail::class);
    }

    public function test_due_trial_without_a_required_payment_method_is_canceled(): void
    {
        config()->set('billing.trial.require_payment_method', true);
        config()->set('billing.trial.no_payment_method_action', 'cancel');

        $organization = $this->makeOrg('org_no_pm');
        $subscription = app(SubscribesOrganizations::class)->subscribeWithTrial($organization, $this->plan('starter'), trialDays: 7);

        // The manual gateway vaults no methods → the policy cancels the due trial.
        Carbon::setTestNow(Carbon::now()->addDays(8));
        Artisan::call('billing:convert-trials');

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $this->assertSame(0, Invoice::query()->where('organization_id', 'org_no_pm')->count());

        Carbon::setTestNow();
    }

    private function makeOrg(string $id): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$id.'.test',
        ]);
    }

    /** @return array<string, string> */
    private function orgWithToken(string $id): array
    {
        $this->makeOrg($id);
        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function plan(string $key): Plan
    {
        return Plan::query()->with(['prices', 'product'])->where('key', $key)->firstOrFail();
    }
}
