<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Mail\PaymentRetryMail;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\PaymentRetryAttempt;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ScriptedPaymentGateway;
use Tests\TestCase;

/**
 * Adaptive, decline-code-aware dunning: the decline category drives the recovery. A hard decline
 * short-circuits (no retries, ask for a new method); insufficient-funds is spread onto the
 * payday-aware curve; do-not-honor gets a longer backoff; authentication-required sends an
 * authenticate link. Driven through the real services with a scripted gateway whose decline
 * codes are the classification input.
 */
class AdaptiveDunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_a_hard_decline_does_not_retry_and_asks_for_a_new_method(): void
    {
        Mail::fake();
        config()->set('billing.payment.retry.terminal_action', 'cancel');
        // A lost card: retrying the same method can never succeed.
        $this->bindGateway([PaymentResult::failed('lost_card')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_hard');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();

        // No schedule was opened — terminal immediately.
        $this->assertSame(PaymentRetry::STATUS_EXHAUSTED, $retry->status);
        $this->assertSame(DeclineCategory::Hard->value, $retry->decline_category);
        $this->assertSame('lost_card', $retry->decline_code);
        $this->assertSame(0, $retry->attempts);
        $this->assertNull($retry->next_attempt_at);

        // The subscription was canceled (the terminal action) — never retried.
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);

        // A retention save-offer was surfaced on entry (the deep-integration seam).
        $this->assertSame('pause_one_cycle', $retry->save_offer_key);

        // The customer is asked for a NEW method (not told "we'll try again").
        Mail::assertQueued(PaymentRetryMail::class, fn (PaymentRetryMail $m): bool => $m->requiresNewMethod === true
            && $m->exhausted === true
            && $m->declineCategory === 'hard'
            && $m->hasTo('billing@org_hard.test'));

        // The gateway was charged exactly once — no retries fired.
        $gateway = app(PaymentGateway::class);
        $this->assertInstanceOf(ScriptedPaymentGateway::class, $gateway);
        $this->assertCount(1, $gateway->charged);
    }

    public function test_insufficient_funds_uses_the_adaptive_payday_aware_curve_not_the_fixed_schedule(): void
    {
        Mail::fake();
        // Freeze the clock to a known Wednesday so the adaptive instant is deterministic.
        Carbon::setTestNow(Carbon::parse('2026-06-10'));
        $this->bindGateway([PaymentResult::failed('insufficient_funds')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_nsf');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame(PaymentRetry::STATUS_RETRYING, $retry->status);
        $this->assertSame(DeclineCategory::InsufficientFunds->value, $retry->decline_category);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);

        // The OLD fixed schedule would retry on day 1 (2026-06-11). The adaptive curve instead
        // spreads to offset 2 (06-12) and pulls forward to the payday anchor (the 15th, a Monday).
        $this->assertNotNull($retry->next_attempt_at);
        $this->assertSame('2026-06-15', $retry->next_attempt_at->toDateString());
        $this->assertNotSame('2026-06-11', $retry->next_attempt_at->toDateString());
        // ...and it is not a weekend.
        $this->assertNotContains($retry->next_attempt_at->isoWeekday(), [6, 7]);

        // The initial failure was logged to the attempts timeline.
        $this->assertSame(1, PaymentRetryAttempt::query()->where('payment_retry_id', $retry->id)->count());

        Carbon::setTestNow();
    }

    public function test_try_again_later_uses_a_longer_backoff_than_the_base_schedule(): void
    {
        Mail::fake();
        $this->bindGateway([PaymentResult::failed('do_not_honor')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_dnh');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame(DeclineCategory::TryAgainLater->value, $retry->decline_category);
        // The try-again-later curve has 5 attempts (vs the base 4) — a longer chase.
        $this->assertSame(5, $retry->max_attempts);
    }

    public function test_authentication_required_sends_an_authenticate_link(): void
    {
        Mail::fake();
        $this->bindGateway([PaymentResult::failed('authentication_required')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_sca');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame(DeclineCategory::NeedsAction->value, $retry->decline_category);
        $this->assertSame(PaymentRetry::STATUS_RETRYING, $retry->status);

        Mail::assertQueued(PaymentRetryMail::class, fn (PaymentRetryMail $m): bool => $m->needsAction === true
            && $m->requiresNewMethod === false
            && $m->declineCategory === 'needs_action');
    }

    public function test_a_soft_decline_that_escalates_to_hard_stops_retrying(): void
    {
        Mail::fake();
        config()->set('billing.payment.retry.terminal_action', 'cancel');
        // First a recoverable decline (opens the schedule), then the retry declines HARD.
        $this->bindGateway([PaymentResult::failed('card_declined'), PaymentResult::failed('stolen_card')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_escalate');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);
        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(DeclineCategory::Recoverable->value, $retry->decline_category);
        $this->assertTrue($retry->isRetrying());

        // The scheduled retry escalates to a hard decline → the schedule is abandoned.
        Carbon::setTestNow(Carbon::now()->addDays(2));
        app(RetriesPayments::class)->retryNow($retry->refresh());

        $retry->refresh();
        $this->assertSame(PaymentRetry::STATUS_EXHAUSTED, $retry->status);
        $this->assertSame(DeclineCategory::Hard->value, $retry->decline_category);
        $this->assertSame('stolen_card', $retry->decline_code);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * @param  list<PaymentResult>  $script
     */
    private function bindGateway(array $script): void
    {
        $this->app->instance(PaymentGateway::class, new ScriptedPaymentGateway($script));
    }

    /** @return array{0: Subscription, 1: Invoice} */
    private function subscribedWithInvoice(string $id): array
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$id.'.test',
        ]);

        $plan = Plan::query()->with(['prices', 'product'])->where('key', 'starter')->firstOrFail();
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan)->refresh()->load('organization', 'plan');
        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        return [$subscription, $invoice];
    }
}
