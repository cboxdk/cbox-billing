<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\DunningStrategy;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedPaymentGateway;
use Tests\TestCase;

/**
 * The adaptive-dunning console: the recovery-analytics dunning screen, the per-subscription
 * decline + adaptive-schedule + attempts-timeline detail, and the runtime strategy editor
 * (view / tune / revert a per-category recovery plan, persisted and read live).
 */
class DunningStrategyConsoleTest extends TestCase
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
    }

    public function test_the_strategy_index_lists_every_category(): void
    {
        $this->withSession($this->session)->get('/settings/dunning')
            ->assertOk()
            ->assertSee('Adaptive retry strategy')
            ->assertSee('Insufficient funds')
            ->assertSee('Hard decline')
            ->assertSee('Try again later');
    }

    public function test_the_edit_form_renders_for_a_category(): void
    {
        $this->withSession($this->session)->get('/settings/dunning/insufficient_funds/edit')
            ->assertOk()
            ->assertSee('Insufficient funds strategy')
            ->assertSee('Backoff');
    }

    public function test_saving_an_override_persists_and_is_read_live_by_the_strategy(): void
    {
        $this->withSession($this->session)
            ->put('/settings/dunning/insufficient_funds', [
                'backoff_days' => '3, 7, 12',
                'max_attempts' => '',
                'retry' => '1',
                'avoid_weekends' => '1',
                'align_to_payday' => '1',
            ])
            ->assertRedirect(route('billing.settings.dunning'));

        // Persisted...
        $row = DunningStrategy::query()->where('category', 'insufficient_funds')->firstOrFail();
        $this->assertSame([3, 7, 12], $row->backoff_days);
        $this->assertTrue($row->avoid_weekends);

        // ...and read live by the strategy (config ⊕ override).
        $plan = app(SchedulesRetries::class)->planFor(DeclineCategory::InsufficientFunds);
        $this->assertSame([3, 7, 12], $plan->backoffDays);
        $this->assertSame(3, $plan->maxAttempts);
    }

    public function test_a_hard_category_override_can_never_enable_retries(): void
    {
        $this->withSession($this->session)
            ->put('/settings/dunning/hard', [
                'backoff_days' => '1, 2, 3',
                'retry' => '1', // attempt to force retries on a hard decline
            ])
            ->assertRedirect();

        // The strategy still refuses to retry a hard decline.
        $this->assertFalse(app(SchedulesRetries::class)->planFor(DeclineCategory::Hard)->retry);
    }

    public function test_resetting_removes_the_override(): void
    {
        DunningStrategy::query()->create([
            'category' => 'recoverable', 'retry' => true, 'backoff_days' => [9], 'avoid_weekends' => false, 'align_to_payday' => false,
        ]);

        $this->withSession($this->session)
            ->post('/settings/dunning/recoverable/reset')
            ->assertRedirect(route('billing.settings.dunning'));

        $this->assertDatabaseMissing('dunning_strategies', ['category' => 'recoverable']);
    }

    public function test_the_dunning_screen_shows_the_decline_category_and_recovery_stats(): void
    {
        [$subscription] = $this->inDunning('org_screen', [PaymentResult::failed('insufficient_funds')]);

        $this->withSession($this->session)->get('/subscriptions/dunning')
            ->assertOk()
            ->assertSee('Recovery rate')
            ->assertSee('Recovery by decline category')
            ->assertSee('Insufficient funds')
            ->assertSee('Retry strategy');

        $this->assertNotNull($subscription);
    }

    public function test_the_subscription_detail_shows_the_adaptive_schedule_and_timeline(): void
    {
        [$subscription] = $this->inDunning('org_detail', [PaymentResult::failed('insufficient_funds')]);

        $this->withSession($this->session)->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertSee('Attempts timeline')
            ->assertSee('Insufficient funds')
            ->assertSee('insufficient_funds');
    }

    public function test_the_dashboard_shows_the_recovery_card(): void
    {
        $this->inDunning('org_dash', [PaymentResult::failed('card_declined')]);

        $this->withSession($this->session)->get('/')
            ->assertOk()
            ->assertSee('Dunning recovery');
    }

    /**
     * @param  list<PaymentResult>  $script
     * @return array{0: Subscription, 1: Invoice, 2: PaymentRetry}
     */
    private function inDunning(string $id, array $script): array
    {
        $this->app->instance(PaymentGateway::class, new ScriptedPaymentGateway($script));

        $organization = Organization::query()->create([
            'id' => $id, 'name' => ucfirst($id), 'billing_country' => 'DK', 'billing_email' => 'billing@'.$id.'.test',
        ]);

        $plan = Plan::query()->with(['prices', 'product'])->where('key', 'starter')->firstOrFail();
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan)->refresh()->load('organization', 'plan');
        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        return [$subscription, $invoice, PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail()];
    }
}
