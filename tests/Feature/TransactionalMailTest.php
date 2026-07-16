<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Jobs\RenewSubscriptionJob;
use App\Jobs\RunOrgDunningJob;
use App\Mail\InvoiceIssuedMail;
use App\Mail\LicenseDeliveryMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\RenewalReminderMail;
use App\Mail\SubscriptionChangedMail;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Dunning\DunningRunner;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LicensingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The transactional-email surface: every lifecycle notification is queued (each Mailable is
 * ShouldQueue) to the customer's billing contact at its real trigger point, carrying the
 * right payload. Driven through the actual services / jobs / webhook — mail is faked and the
 * queued send asserted.
 */
class TransactionalMailTest extends TestCase
{
    use RefreshDatabase;

    private const string WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);
        $this->seed(CatalogSeeder::class);
    }

    public function test_invoice_issued_is_emailed_to_the_billing_contact(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_inv', 'starter');

        app(GeneratesInvoices::class)->generate($subscription);

        Mail::assertQueued(InvoiceIssuedMail::class, function (InvoiceIssuedMail $mail): bool {
            return $mail->hasTo('billing@org_inv.test')
                && str_starts_with($mail->invoiceNumber, 'CBOX-DK')
                && $mail->organizationName === 'Org_inv';
        });
    }

    public function test_no_mail_is_sent_when_the_org_has_no_billing_contact(): void
    {
        Mail::fake();
        $organization = Organization::query()->create([
            'id' => 'org_nomail', 'name' => 'No Mail', 'billing_country' => 'DK', 'billing_email' => null,
        ]);
        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $this->plan('starter'));

        app(GeneratesInvoices::class)->generate($subscription->refresh()->load('organization', 'plan'));

        Mail::assertNothingQueued();
    }

    public function test_payment_receipt_is_emailed_on_a_settled_webhook(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_pay', 'starter');
        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        $this->postSettlement([
            'event_id' => 'evt_paid_1',
            'type' => 'payment.settled',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ])->assertOk()->assertJsonPath('applied', true);

        Mail::assertQueued(PaymentReceiptMail::class, function (PaymentReceiptMail $mail) use ($invoice): bool {
            return $mail->hasTo('billing@org_pay.test') && $mail->invoiceNumber === $invoice->number;
        });
    }

    public function test_a_redelivered_settlement_does_not_send_a_second_receipt(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_pay2', 'starter');
        $invoice = app(GeneratesInvoices::class)->generate($subscription);

        $event = [
            'event_id' => 'evt_paid_2',
            'type' => 'payment.settled',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ];

        $this->postSettlement($event)->assertOk();
        $this->postSettlement($event)->assertOk(); // exactly-once no-op

        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_dunning_emails_a_notice_for_a_past_due_account(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_dun', 'starter');
        $this->pastDueInvoice($subscription, daysAgo: 5);

        (new RunOrgDunningJob('org_dun'))->handle(app(DunningRunner::class), app(NotifiesCustomers::class));

        Mail::assertQueued(PaymentFailedMail::class, function (PaymentFailedMail $mail): bool {
            return $mail->hasTo('billing@org_dun.test') && $mail->suspended === false;
        });
    }

    public function test_renewal_reminder_is_emailed_ahead_of_the_term(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_ren', 'starter');
        // Period ends exactly at the lead window (7 days) → the reminder fires once.
        $subscription->forceFill(['current_period_end' => Carbon::now()->addDays(7)])->save();

        (new RenewSubscriptionJob($subscription->id))->handle(
            app(CycleRenewalService::class),
            app(NotifiesCustomers::class),
            app('config'),
        );

        Mail::assertQueued(RenewalReminderMail::class, function (RenewalReminderMail $mail): bool {
            return $mail->hasTo('billing@org_ren.test') && $mail->planName !== '';
        });
    }

    public function test_no_renewal_reminder_when_the_term_is_far_off(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_far', 'starter');
        $subscription->forceFill(['current_period_end' => Carbon::now()->addDays(20)])->save();

        (new RenewSubscriptionJob($subscription->id))->handle(
            app(CycleRenewalService::class),
            app(NotifiesCustomers::class),
            app('config'),
        );

        Mail::assertNotQueued(RenewalReminderMail::class);
    }

    public function test_plan_change_and_cancel_email_the_customer(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_chg', 'starter');

        app(SubscribesOrganizations::class)->changePlan($subscription, $this->plan('team'));
        Mail::assertQueued(SubscriptionChangedMail::class, fn (SubscriptionChangedMail $m): bool => $m->changeType === 'plan_change' && $m->hasTo('billing@org_chg.test'));

        app(SubscribesOrganizations::class)->cancel($subscription->refresh()->load('plan', 'organization'), true);
        Mail::assertQueued(SubscriptionChangedMail::class, fn (SubscriptionChangedMail $m): bool => $m->changeType === 'cancel_scheduled');
    }

    public function test_license_issue_emails_the_key_to_the_customer(): void
    {
        Mail::fake();
        $this->seed(LicensingSeeder::class);
        $keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $keyPair['privateKey'],
            'billing.licensing.public_key' => $keyPair['publicKey'],
        ]);
        Organization::query()->create([
            'id' => 'org_lic', 'name' => 'Lic Co', 'billing_country' => 'DK', 'billing_email' => 'ops@org_lic.test',
        ]);

        $license = app(IssuesLicenses::class)->issue(customerId: 'org_lic', planId: 'enterprise-onprem');

        Mail::assertQueued(LicenseDeliveryMail::class, function (LicenseDeliveryMail $mail) use ($license): bool {
            return $mail->hasTo('ops@org_lic.test')
                && $mail->licenseKey === $license->key
                && $mail->reissued === false;
        });
    }

    private function subscribeOrg(string $id, string $planKey): Subscription
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$id.'.test',
        ]);

        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $this->plan($planKey));

        return $subscription->refresh()->load('organization', 'plan');
    }

    private function plan(string $key): Plan
    {
        return Plan::query()->with(['prices', 'product'])->where('key', $key)->firstOrFail();
    }

    private function pastDueInvoice(Subscription $subscription, int $daysAgo): Invoice
    {
        return Invoice::query()->create([
            'organization_id' => $subscription->organization_id,
            'seller' => 'cbox-dk',
            'number' => 'CBOX-DK-PAST-'.$subscription->id,
            'currency' => 'DKK',
            'subtotal_minor' => 100_000,
            'tax_minor' => 25_000,
            'total_minor' => 125_000,
            'status' => 'open',
            'issued_at' => Carbon::now()->subDays($daysAgo + 14),
            'due_at' => Carbon::now()->subDays($daysAgo),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postSettlement(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/webhooks/manual',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => $signature],
            $body,
        );
    }
}
