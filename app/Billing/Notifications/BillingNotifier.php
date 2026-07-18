<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Support\MoneyFormatter;
use App\Mail\InvoiceIssuedMail;
use App\Mail\LicenseDeliveryMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentRetryMail;
use App\Mail\PlanRetiringMail;
use App\Mail\RenewalReminderMail;
use App\Mail\SubscriptionChangedMail;
use App\Mail\TrialEndingMail;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\Billing\Money\Money;
use DateTimeInterface;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the customer recipient and queues the branded Mailable for each lifecycle event.
 * The one place recipient policy lives: mail goes to the organization's billing contact, and
 * an account with none on file is skipped (logged) rather than sent to a fabricated address.
 * Thin — it maps domain objects to Mailable payloads and hands off to the mailer, which
 * queues them (every Mailable is `ShouldQueue`).
 */
readonly class BillingNotifier implements NotifiesCustomers
{
    public function __construct(private LoggerInterface $log) {}

    public function invoiceIssued(Invoice $invoice, Subscription $subscription): void
    {
        $organization = $invoice->organization ?? $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $this->send($organization, new InvoiceIssuedMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: MoneyFormatter::money($invoice->total()),
            periodLabel: $this->periodLabel($subscription),
            issuedAtLabel: $this->date($invoice->issued_at),
            dueAtLabel: $this->date($invoice->due_at),
        ), 'invoice.issued', $invoice->number);
    }

    public function paymentReceipt(Invoice $invoice): void
    {
        $organization = $invoice->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $this->send($organization, new PaymentReceiptMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: MoneyFormatter::money($invoice->total()),
            paidAtLabel: $this->date($invoice->paid_at),
            gatewayReference: $invoice->gateway_reference,
        ), 'payment.receipt', $invoice->number);
    }

    public function dunningNotice(Organization $organization, Money $amountDue, bool $suspended, ?DateTimeInterface $oldestDueAt): void
    {
        $this->send($organization, new PaymentFailedMail(
            organizationName: $organization->name,
            amountDueFormatted: MoneyFormatter::money($amountDue),
            suspended: $suspended,
            oldestDueLabel: $oldestDueAt?->format('j M Y'),
        ), 'dunning.notice', $organization->id);
    }

    public function paymentRetryFailed(Subscription $subscription, Invoice $invoice, int $attempt, int $maxAttempts, ?DateTimeInterface $nextAttemptAt, bool $exhausted): void
    {
        $organization = $invoice->organization ?? $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $this->send($organization, new PaymentRetryMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: MoneyFormatter::money($invoice->total()),
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            nextAttemptLabel: $nextAttemptAt?->format('j M Y'),
            exhausted: $exhausted,
        ), 'payment.retry', $invoice->number);
    }

    public function trialEnding(Subscription $subscription, DateTimeInterface $trialEndsAt): void
    {
        $organization = $subscription->organization;
        $plan = $subscription->plan;

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $currency = $organization->billing_currency ?? 'DKK';

        $this->send($organization, new TrialEndingMail(
            organizationName: $organization->name,
            planName: $plan->name,
            endsAtLabel: $trialEndsAt->format('j M Y'),
            amountFormatted: $this->recurringAmount($plan, $currency),
        ), 'trial.ending', $subscription->organization_id);
    }

    public function renewalReminder(Subscription $subscription): void
    {
        $organization = $subscription->organization;
        $plan = $subscription->plan;

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $currency = $organization->billing_currency ?? 'DKK';

        $this->send($organization, new RenewalReminderMail(
            organizationName: $organization->name,
            planName: $plan->name,
            renewsAtLabel: $this->date($subscription->current_period_end),
            amountFormatted: $this->recurringAmount($plan, $currency),
        ), 'renewal.reminder', $subscription->organization_id);
    }

    public function subscriptionChanged(Subscription $subscription, string $changeType, ?string $previousPlanName = null): void
    {
        $organization = $subscription->organization;
        $plan = $subscription->plan;

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $this->send($organization, new SubscriptionChangedMail(
            organizationName: $organization->name,
            changeType: $changeType,
            planName: $plan->name,
            previousPlanName: $previousPlanName,
            effectiveAtLabel: $changeType === 'cancel_scheduled'
                ? $this->date($subscription->current_period_end)
                : null,
        ), 'subscription.'.$changeType, $subscription->organization_id);
    }

    public function planRetiring(Subscription $subscription, Plan $plan, string $retiresAtLabel, string $renewalDueLabel, ?string $defaultSuccessorName): void
    {
        $organization = $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $this->send($organization, new PlanRetiringMail(
            organizationName: $organization->name,
            planName: $plan->name,
            retiresAtLabel: $retiresAtLabel,
            renewalDueLabel: $renewalDueLabel,
            defaultSuccessorName: $defaultSuccessorName,
        ), 'plan.retiring', $subscription->organization_id);
    }

    public function licenseDelivered(Organization $organization, IssuedLicense $license, bool $reissued): void
    {
        $this->send($organization, new LicenseDeliveryMail(
            organizationName: $organization->name,
            licenseKey: $license->key,
            planLabel: $license->plan,
            deploymentId: $license->deploymentId,
            expiresAtLabel: $license->expiresAt->format('j M Y'),
            reissued: $reissued,
        ), 'license.delivered', $organization->id);
    }

    /**
     * Queue `$mailable` to the org's billing contact, or skip + log when there is none.
     * `$event` / `$subject` only annotate the skip log line, so a missing recipient is
     * auditable rather than silent.
     */
    private function send(Organization $organization, Mailable $mailable, string $event, string $subject): void
    {
        $recipient = $organization->billingContact();

        if ($recipient === null) {
            $this->log->warning('Skipped billing notification: no billing contact on file.', [
                'event' => $event,
                'subject' => $subject,
                'organization' => $organization->id,
            ]);

            return;
        }

        Mail::to($recipient)->queue($mailable);
    }

    /** The plan's recurring amount in the account currency, or a best-effort 'n/a' when it is not priced there. */
    private function recurringAmount(Plan $plan, string $currency): string
    {
        try {
            return MoneyFormatter::money($plan->priceFor($currency));
        } catch (Throwable) {
            return 'n/a';
        }
    }

    private function periodLabel(Subscription $subscription): string
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return 'the current period';
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    }

    private function date(?Carbon $date): string
    {
        return $date?->format('j M Y') ?? 'n/a';
    }
}
