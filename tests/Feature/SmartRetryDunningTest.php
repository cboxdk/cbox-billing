<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentRetryMail;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ScriptedPaymentGateway;
use Tests\TestCase;

/**
 * Smart-retry dunning for a failed renewal charge: a hard decline moves the subscription to
 * PastDue and opens a backoff schedule; a retry that settles recovers it to Active (+
 * receipt); an exhausted schedule runs the terminal action (cancel). Driven through the real
 * services with a scripted gateway so the decline → retry transitions are deterministic.
 */
class SmartRetryDunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_failed_charge_moves_to_past_due_then_a_retry_recovers_to_active_with_receipt(): void
    {
        Mail::fake();
        // Charge 1 (renewal) declines; charge 2 (the scheduled retry) settles.
        $this->bindGateway([PaymentResult::failed('card_declined'), PaymentResult::succeeded('gw_ok')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_retry');

        // The renewal charge fails → PastDue + a retry row + the initial payment-failed email.
        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
        $this->assertFalse($invoice->refresh()->isPaid());

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(PaymentRetry::STATUS_RETRYING, $retry->status);
        $this->assertSame(0, $retry->attempts);
        $this->assertNotNull($retry->next_attempt_at);

        Mail::assertQueued(PaymentRetryMail::class, fn (PaymentRetryMail $m): bool => $m->attempt === 0 && $m->exhausted === false && $m->hasTo('billing@org_retry.test'));

        // Advance past the first backoff offset and run the retry pass — the scheduled retry
        // settles, marking the invoice paid, recovering the subscription, and sending a receipt.
        Carbon::setTestNow(Carbon::now()->addDays(2));
        Artisan::call('billing:retry-payments');

        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(PaymentRetry::STATUS_RECOVERED, $retry->refresh()->status);
        $this->assertSame(1, $retry->attempts);
        $this->assertNull($retry->next_attempt_at);

        Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $m): bool => $m->invoiceNumber === $invoice->number);

        Carbon::setTestNow();
    }

    public function test_an_exhausted_retry_schedule_cancels_the_subscription(): void
    {
        Mail::fake();
        // A single-attempt schedule so one failed retry exhausts it; every charge declines.
        config()->set('billing.payment.retry.schedule', [1]);
        config()->set('billing.payment.retry.terminal_action', 'cancel');
        $this->bindGateway([PaymentResult::failed('card_declined')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_exhaust');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(1, $retry->max_attempts);

        // The one scheduled retry also declines → schedule exhausted → terminal cancel.
        Carbon::setTestNow(Carbon::now()->addDays(2));
        Artisan::call('billing:retry-payments');

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertSame(PaymentRetry::STATUS_EXHAUSTED, $retry->refresh()->status);
        $this->assertFalse($invoice->refresh()->isPaid());

        Mail::assertQueued(PaymentRetryMail::class, fn (PaymentRetryMail $m): bool => $m->exhausted === true);

        Carbon::setTestNow();
    }

    public function test_a_pending_out_of_band_charge_does_not_enter_the_retry_flow(): void
    {
        Mail::fake();
        // A gateway that settles out of band reports pending — not a failure.
        $this->bindGateway([PaymentResult::pending('pi_1')]);

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_pending');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        // Still Active, no retry row, no payment-failed email: the settlement webhook resolves it.
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(0, PaymentRetry::query()->count());
        Mail::assertNotQueued(PaymentRetryMail::class);
    }

    /**
     * Bind the scripted gateway for this test's charge sequence.
     *
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
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan);
        $subscription = $subscription->refresh()->load('organization', 'plan');

        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        return [$subscription, $invoice];
    }
}
