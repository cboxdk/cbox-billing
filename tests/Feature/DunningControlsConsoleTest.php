<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedPaymentGateway;
use Tests\TestCase;

/**
 * Manual dunning controls (Wave 3): "retry now" fires one attempt through the engine
 * idempotently, and "stop dunning" halts the schedule with the terminal-action choice
 * (cancel or leave past due). Both are server-guarded and reconcile through the engine.
 */
class DunningControlsConsoleTest extends TestCase
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

        // The renewal charge (script[0]) fails → PastDue + a retry row.
        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        return [$subscription, $invoice, PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail()];
    }

    public function test_retry_now_fires_one_attempt_and_recovers_on_settlement(): void
    {
        // Renewal declines; the manual retry settles.
        [$subscription, $invoice, $retry] = $this->inDunning('org_retrynow', [
            PaymentResult::failed('card_declined'),
            PaymentResult::succeeded('gw_manual'),
        ]);

        $this->assertSame(0, $retry->attempts);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);

        $this->withSession($this->session)->post('/subscriptions/dunning/'.$retry->id.'/retry')
            ->assertRedirect()->assertSessionHas('status');

        // Exactly one attempt fired; it settled, so the invoice is paid and the sub recovered.
        $retry->refresh();
        $this->assertSame(1, $retry->attempts);
        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(PaymentRetry::STATUS_RECOVERED, $retry->status);
    }

    public function test_stop_dunning_leaves_past_due_by_default(): void
    {
        [$subscription, , $retry] = $this->inDunning('org_stopkeep', [PaymentResult::failed('card_declined')]);

        $this->withSession($this->session)->post('/subscriptions/dunning/'.$retry->id.'/stop', ['terminal' => 'keep'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(PaymentRetry::STATUS_STOPPED, $retry->refresh()->status);
        $this->assertNull($retry->next_attempt_at);
        // The subscription is left past due (not canceled).
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
    }

    public function test_stop_dunning_can_cancel_the_subscription(): void
    {
        [$subscription, , $retry] = $this->inDunning('org_stopcancel', [PaymentResult::failed('card_declined')]);

        $this->withSession($this->session)->post('/subscriptions/dunning/'.$retry->id.'/stop', ['terminal' => 'cancel'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(PaymentRetry::STATUS_STOPPED, $retry->refresh()->status);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
    }

    public function test_stop_on_an_inactive_schedule_is_a_no_op(): void
    {
        [, , $retry] = $this->inDunning('org_noop', [PaymentResult::failed('card_declined')]);
        $retry->forceFill(['status' => PaymentRetry::STATUS_RECOVERED])->save();

        $this->withSession($this->session)->post('/subscriptions/dunning/'.$retry->id.'/stop', ['terminal' => 'cancel'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(PaymentRetry::STATUS_RECOVERED, $retry->refresh()->status);
    }

    public function test_the_dunning_screen_shows_the_manual_controls(): void
    {
        [, , $retry] = $this->inDunning('org_screen', [PaymentResult::failed('card_declined')]);

        $this->withSession($this->session)->get('/subscriptions/dunning')
            ->assertOk()
            ->assertSee('Retry now')
            ->assertSee('Stop')
            ->assertSee('data-confirm', false);

        $this->assertNotNull($retry);
    }
}
