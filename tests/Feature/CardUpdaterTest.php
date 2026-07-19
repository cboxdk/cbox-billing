<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
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
use Illuminate\Testing\TestResponse;
use Tests\Support\ScriptedPaymentGateway;
use Tests\TestCase;

/**
 * The card / account-updater seam: a verified card-update webhook (a network account-updater
 * push) points the vaulted default at the fresh card and immediately re-attempts the account's
 * in-dunning charge — the recovery that re-opens after a card goes bad. Deny-by-default when no
 * signing secret is configured.
 */
class CardUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);
    }

    public function test_a_card_update_reattempts_an_in_dunning_charge_and_recovers_it(): void
    {
        // The renewal declines (opens dunning); the card-updater re-attempt then settles.
        $this->app->instance(PaymentGateway::class, new ScriptedPaymentGateway([
            PaymentResult::failed('insufficient_funds'),
            PaymentResult::succeeded('gw_after_update'),
        ]));

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_updater');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertTrue($retry->isRetrying());
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);

        // The network pushes a fresh card for the account.
        $response = $this->postCardUpdate([
            'event_id' => 'evt_card_1',
            'type' => 'payment_method.automatically_updated',
            'account' => 'org_updater',
            'payment_method_id' => 'pm_fresh',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2031,
        ]);

        $response->assertOk();
        $response->assertJson(['applied' => true, 'organization' => 'org_updater', 'reattempted' => 1, 'recovered' => 1]);

        // The invoice settled and dunning closed as recovered.
        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame(PaymentRetry::STATUS_RECOVERED, $retry->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);

        // The card-update event and the recovery are both on the timeline.
        $this->assertSame(1, PaymentRetryAttempt::query()
            ->where('payment_retry_id', $retry->id)
            ->where('outcome', PaymentRetryAttempt::OUTCOME_CARD_UPDATED)
            ->count());
    }

    public function test_a_card_update_reopens_a_hard_declined_but_still_serving_subscription(): void
    {
        // A hard decline that leaves the subscription past due (terminal action 'none'); the
        // fresh card then re-opens and recovers it.
        config()->set('billing.payment.retry.terminal_action', 'none');
        $this->app->instance(PaymentGateway::class, new ScriptedPaymentGateway([
            PaymentResult::failed('lost_card'),
            PaymentResult::succeeded('gw_new_card'),
        ]));

        [$subscription, $invoice] = $this->subscribedWithInvoice('org_reopen');

        app(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        $retry = PaymentRetry::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(PaymentRetry::STATUS_EXHAUSTED, $retry->status); // hard → no schedule
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status); // left serving

        $this->postCardUpdate([
            'event_id' => 'evt_card_2',
            'type' => 'payment_method.automatically_updated',
            'account' => 'org_reopen',
            'payment_method_id' => 'pm_brand_new',
        ])->assertOk()->assertJson(['reattempted' => 1, 'recovered' => 1]);

        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame(PaymentRetry::STATUS_RECOVERED, $retry->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    public function test_an_unsigned_card_update_is_refused(): void
    {
        $body = json_encode(['event_id' => 'x', 'type' => 'payment_method.updated', 'account' => 'org_x', 'payment_method_id' => 'pm']);

        $response = $this->call('POST', '/webhooks/manual/payment-method', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CBOX_SIGNATURE' => 'not-the-right-signature',
        ], $body === false ? '' : $body);

        $response->assertStatus(400);
    }

    public function test_deny_by_default_when_no_secret_is_configured(): void
    {
        config()->set('billing.webhook.secret', null);

        // A correctly-formed (but unverifiable, since no secret) payload is still refused.
        $body = json_encode(['event_id' => 'x', 'type' => 'payment_method.updated', 'account' => 'org_x', 'payment_method_id' => 'pm']);

        $this->call('POST', '/webhooks/manual/payment-method', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CBOX_SIGNATURE' => hash_hmac('sha256', $body === false ? '' : $body, 'anything'),
        ], $body === false ? '' : $body)->assertStatus(400);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postCardUpdate(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        return $this->call('POST', '/webhooks/manual/payment-method', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CBOX_SIGNATURE' => $signature,
        ], $body);
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
