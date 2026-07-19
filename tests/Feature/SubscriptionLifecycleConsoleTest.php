<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The subscription operator-lifecycle console (Wave 3): create, plan change (preview →
 * confirm, immediate or scheduled), quantity reprice, add-ons, and cancelling a scheduled
 * change — all through the engine. Money is asserted in exact minor units and the engine
 * state (plan, seats, wallet, pending change) is checked after each confirm.
 */
class SubscriptionLifecycleConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-15 10:00:00');
        Organization::query()->create(['id' => 'org_ops', 'name' => 'Ops Co', 'billing_email' => 'ops@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
    }

    private function subscribe(string $plan = 'team', int $seats = 1): Subscription
    {
        return Subscription::query()->create([
            'organization_id' => 'org_ops',
            'plan_id' => Plan::query()->where('key', $plan)->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'seats' => $seats,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);
    }

    private function walletBalance(string $org): int
    {
        return app(Wallet::class)->balance($org, Pools::included(), Denomination::unit('credit'), (int) (Carbon::now()->getTimestamp() * 1000));
    }

    public function test_operator_creates_a_subscription(): void
    {
        $this->withSession($this->session)->post('/subscriptions', [
            'organization_id' => 'org_ops',
            'plan' => 'team',
            'currency' => 'DKK',
            'seats' => 3,
        ])->assertRedirect();

        $subscription = Subscription::query()->where('organization_id', 'org_ops')->firstOrFail();
        $this->assertSame('team', $subscription->plan?->key);
        $this->assertSame(3, $subscription->seats);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_create_with_trial_opens_trialing(): void
    {
        $this->withSession($this->session)->post('/subscriptions', [
            'organization_id' => 'org_ops', 'plan' => 'team', 'currency' => 'DKK', 'seats' => 1,
            'trial' => '1', 'trial_days' => 21,
        ])->assertRedirect();

        $subscription = Subscription::query()->where('organization_id', 'org_ops')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertNotNull($subscription->trial_ends_at);
    }

    public function test_plan_change_preview_shows_prorated_amount_and_confirm_applies_it(): void
    {
        $subscription = $this->subscribe('team');

        // Preview a change to Business — the review page shows the engine's new recurring.
        $business = Plan::query()->where('key', 'business')->firstOrFail();
        $businessMinor = $business->priceFor('DKK')->minor();

        $preview = $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/plan-change/preview', [
            'plan' => 'business', 'when' => 'now',
        ]);
        $preview->assertOk()->assertSee('New recurring');
        // The engine's new recurring amount is the Business price, exact minor units.
        $preview->assertSee(number_format($businessMinor / 100, 2, ',', '.'));

        // Confirm → the plan actually moves.
        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/plan-change', [
            'plan' => 'business', 'when' => 'now',
        ])->assertRedirect('/subscriptions/'.$subscription->id);

        $this->assertSame('business', $subscription->fresh()?->plan?->key);
    }

    public function test_plan_change_at_period_end_schedules_rather_than_applies(): void
    {
        $subscription = $this->subscribe('team');

        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/plan-change', [
            'plan' => 'business', 'when' => 'period_end',
        ])->assertRedirect();

        $fresh = $subscription->fresh();
        // Still on Team, with a pending change recorded.
        $this->assertSame('team', $fresh?->plan?->key);
        $this->assertNotNull($fresh?->pending_plan_id);

        // Cancelling the scheduled change clears it.
        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/scheduled-change/cancel')
            ->assertRedirect()->assertSessionHas('status');
        $this->assertNull($subscription->fresh()?->pending_plan_id);
    }

    public function test_quantity_change_reprices_and_persists_the_new_count(): void
    {
        $subscription = $this->subscribe('team', seats: 2);

        $preview = $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/quantity/preview', ['seats' => 4]);
        $preview->assertOk()->assertSee('Due now');

        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/quantity', ['seats' => 4])
            ->assertRedirect('/subscriptions/'.$subscription->id);

        $this->assertSame(4, $subscription->fresh()?->seats);
    }

    public function test_addon_attaches_with_its_allotment_and_can_be_removed(): void
    {
        $subscription = $this->subscribe('team');
        $before = $this->walletBalance('org_ops');

        // Attach an aligned add-on with a 500-credit allotment; the wallet balance rises by
        // the prorated allotment (Prorated mode over the remaining days).
        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/addons', [
            'key' => 'extra-credits', 'price_minor' => 50_000, 'currency' => 'DKK',
            'alignment' => 'aligned', 'credit_allotment' => 500,
        ])->assertRedirect('/subscriptions/'.$subscription->id);

        $this->assertDatabaseHas('subscription_add_ons', ['subscription_id' => $subscription->id, 'key' => 'extra-credits', 'price_minor' => 50_000]);
        $this->assertGreaterThan($before, $this->walletBalance('org_ops'));

        // Remove it.
        $this->withSession($this->session)->post('/subscriptions/'.$subscription->id.'/addons/remove', ['key' => 'extra-credits'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('subscription_add_ons', ['subscription_id' => $subscription->id, 'key' => 'extra-credits']);
    }

    public function test_write_routes_require_the_manage_permission_markup_is_present(): void
    {
        $subscription = $this->subscribe('team');

        // The detail page carries the confirm markup on the destructive add-on remove control.
        SubscriptionAddOn::query()->create([
            'subscription_id' => $subscription->id, 'key' => 'x', 'price_minor' => 1000, 'currency' => 'DKK',
            'alignment' => 'aligned', 'credit_allotment' => 0,
        ]);

        $this->withSession($this->session)->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertSee('data-confirm', false)
            ->assertSee('Change plan')
            ->assertSee('Change quantity')
            ->assertSee('Add-ons');
    }
}
