<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use App\Billing\Mode\BillingContext;
use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Notifications\Rendering\RenderedMail;
use App\Billing\Support\MoneyFormatter;
use App\Billing\TestMode\CapturedNotifications;
use App\Mail\InvoiceIssuedMail;
use App\Mail\LicenseDeliveryMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentRetryMail;
use App\Mail\PlanRetiringMail;
use App\Mail\RenewalReminderMail;
use App\Mail\SubscriptionChangedMail;
use App\Mail\TransactionalMailable;
use App\Mail\TrialEndingMail;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SellerEntity;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\Billing\Money\Money;
use DateTimeInterface;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the customer recipient, the issuing seller and the customer's locale for each
 * lifecycle event, then formats the money/date payload IN that locale and queues the branded,
 * localized Mailable. The one place recipient policy lives: mail goes to the organization's
 * billing contact, and an account with none on file is skipped (logged) rather than sent to a
 * fabricated address.
 *
 * Thin — it maps domain objects to a locale-formatted variable payload, stamps the mailable
 * with the resolved seller + locale, and hands off to the mailer (or, in test mode, to the
 * capture sink). The rendering itself (template resolution → branding → layout) lives in the
 * mailable's compose seam, not here.
 */
readonly class BillingNotifier implements NotifiesCustomers
{
    public function __construct(
        private LoggerInterface $log,
        private BillingContext $context,
        private CapturedNotifications $captured,
        private LocaleResolver $locales,
        private ComposesTransactionalMail $composer,
    ) {}

    /**
     * Send a console test email for `$event` to `$recipient`, rendered with the event's sample
     * variables and the given seller/locale — the exact pipeline a real lifecycle mail uses.
     * It honours the SAME test-mode gate: in test mode it is captured (not delivered) so a
     * sandbox test-send never touches a real inbox; in live mode it is delivered. Returns true
     * when captured, false when delivered.
     */
    public function sendTest(MailEventType $event, ?string $sellerId, string $locale, string $recipient): bool
    {
        $resolvedLocale = $this->locales->resolve($locale, $this->sellerDefaultLocale($sellerId));
        $rendered = $this->composer->compose($event, $event->sampleVariables(), $sellerId, $resolvedLocale);

        if ($this->context->isTest()) {
            $this->captured->capture('test.'.$event->value, $event->label(), $recipient);

            return true;
        }

        $this->deliver($rendered, $recipient);

        return false;
    }

    private function deliver(RenderedMail $rendered, string $recipient): void
    {
        Mail::html($rendered->html, function (Message $message) use ($recipient, $rendered): void {
            $message->to($recipient)->subject($rendered->subject);

            if ($rendered->fromEmail !== '') {
                $message->from($rendered->fromEmail, $rendered->fromName);
            }

            if ($rendered->replyTo !== null && $rendered->replyTo !== '') {
                $message->replyTo($rendered->replyTo);
            }
        });
    }

    public function invoiceIssued(Invoice $invoice, Subscription $subscription): void
    {
        $organization = $invoice->organization ?? $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $locale = $this->localeFor($organization, $invoice->seller);

        $this->send($organization, $invoice->seller, $locale, new InvoiceIssuedMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: $this->money($invoice->total(), $locale),
            periodLabel: $this->periodLabel($subscription, $locale),
            issuedAtLabel: $this->date($invoice->issued_at, $locale),
            dueAtLabel: $this->date($invoice->due_at, $locale),
        ), 'invoice.issued', $invoice->number);
    }

    public function invoiceResent(Invoice $invoice): void
    {
        $organization = $invoice->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $locale = $this->localeFor($organization, $invoice->seller);

        $this->send($organization, $invoice->seller, $locale, new InvoiceIssuedMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: $this->money($invoice->total(), $locale),
            periodLabel: 'your invoice',
            issuedAtLabel: $this->date($invoice->issued_at, $locale),
            dueAtLabel: $this->date($invoice->due_at, $locale),
        ), 'invoice.resent', $invoice->number);
    }

    public function paymentReceipt(Invoice $invoice): void
    {
        $organization = $invoice->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $locale = $this->localeFor($organization, $invoice->seller);

        $this->send($organization, $invoice->seller, $locale, new PaymentReceiptMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: $this->money($invoice->total(), $locale),
            paidAtLabel: $this->date($invoice->paid_at, $locale),
            gatewayReference: $invoice->gateway_reference,
        ), 'payment.receipt', $invoice->number);
    }

    public function dunningNotice(Organization $organization, Money $amountDue, bool $suspended, ?DateTimeInterface $oldestDueAt): void
    {
        $locale = $this->localeFor($organization, null);

        $this->send($organization, null, $locale, new PaymentFailedMail(
            organizationName: $organization->name,
            amountDueFormatted: $this->money($amountDue, $locale),
            suspended: $suspended,
            oldestDueLabel: $this->dateTime($oldestDueAt, $locale),
        ), 'dunning.notice', $organization->id);
    }

    public function paymentRetryFailed(Subscription $subscription, Invoice $invoice, int $attempt, int $maxAttempts, ?DateTimeInterface $nextAttemptAt, bool $exhausted): void
    {
        $organization = $invoice->organization ?? $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $locale = $this->localeFor($organization, $invoice->seller);

        $this->send($organization, $invoice->seller, $locale, new PaymentRetryMail(
            organizationName: $organization->name,
            invoiceNumber: $invoice->number,
            amountFormatted: $this->money($invoice->total(), $locale),
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            nextAttemptLabel: $this->dateTime($nextAttemptAt, $locale),
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

        $locale = $this->localeFor($organization, null);
        $currency = $organization->billing_currency ?? 'DKK';

        $this->send($organization, null, $locale, new TrialEndingMail(
            organizationName: $organization->name,
            planName: $plan->name,
            endsAtLabel: $this->dateTime($trialEndsAt, $locale) ?? 'n/a',
            amountFormatted: $this->recurringAmount($plan, $currency, $locale),
        ), 'trial.ending', $subscription->organization_id);
    }

    public function renewalReminder(Subscription $subscription): void
    {
        $organization = $subscription->organization;
        $plan = $subscription->plan;

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $locale = $this->localeFor($organization, null);
        $currency = $organization->billing_currency ?? 'DKK';

        $this->send($organization, null, $locale, new RenewalReminderMail(
            organizationName: $organization->name,
            planName: $plan->name,
            renewsAtLabel: $this->date($subscription->current_period_end, $locale),
            amountFormatted: $this->recurringAmount($plan, $currency, $locale),
        ), 'renewal.reminder', $subscription->organization_id);
    }

    public function subscriptionChanged(Subscription $subscription, string $changeType, ?string $previousPlanName = null): void
    {
        $organization = $subscription->organization;
        $plan = $subscription->plan;

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $locale = $this->localeFor($organization, null);

        $this->send($organization, null, $locale, new SubscriptionChangedMail(
            organizationName: $organization->name,
            changeType: $changeType,
            planName: $plan->name,
            previousPlanName: $previousPlanName,
            effectiveAtLabel: $changeType === 'cancel_scheduled'
                ? $this->date($subscription->current_period_end, $locale)
                : null,
        ), 'subscription.'.$changeType, $subscription->organization_id);
    }

    public function planRetiring(Subscription $subscription, Plan $plan, string $retiresAtLabel, string $renewalDueLabel, ?string $defaultSuccessorName): void
    {
        $organization = $subscription->organization;

        if (! $organization instanceof Organization) {
            return;
        }

        $locale = $this->localeFor($organization, null);

        $this->send($organization, null, $locale, new PlanRetiringMail(
            organizationName: $organization->name,
            planName: $plan->name,
            retiresAtLabel: $retiresAtLabel,
            renewalDueLabel: $renewalDueLabel,
            defaultSuccessorName: $defaultSuccessorName,
        ), 'plan.retiring', $subscription->organization_id);
    }

    public function licenseDelivered(Organization $organization, IssuedLicense $license, bool $reissued): void
    {
        $locale = $this->localeFor($organization, null);

        $this->send($organization, null, $locale, new LicenseDeliveryMail(
            organizationName: $organization->name,
            licenseKey: $license->key,
            planLabel: $license->plan,
            deploymentId: $license->deploymentId,
            expiresAtLabel: $this->dateTime($license->expiresAt, $locale) ?? 'n/a',
            reissued: $reissued,
        ), 'license.delivered', $organization->id);
    }

    /**
     * Stamp the mailable with the resolved seller + locale, then queue it to the org's billing
     * contact — or skip + log when there is none. `$event` / `$subject` only annotate the skip
     * log line, so a missing recipient is auditable rather than silent.
     */
    private function send(Organization $organization, ?string $sellerId, string $locale, TransactionalMailable $mailable, string $event, string $subject): void
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

        $mailable->brand($sellerId, $locale);

        // Test mode never delivers: a sandbox lifecycle event is captured (and logged), never
        // queued to the mailer, so no real inbox is ever touched while an integrator drives a
        // year of renewals/dunning through a test clock.
        if ($this->context->isTest()) {
            $this->captured->capture($event, $subject, $recipient);

            return;
        }

        Mail::to($recipient)->queue($mailable);
    }

    /** The locale to render in: customer → issuing seller's default → app fallback. */
    private function localeFor(Organization $organization, ?string $sellerId): string
    {
        return $this->locales->resolve($organization->locale, $this->sellerDefaultLocale($sellerId));
    }

    private function sellerDefaultLocale(?string $sellerId): ?string
    {
        $entity = $sellerId !== null && $sellerId !== ''
            ? SellerEntity::query()->whereKey($sellerId)->first()
            : SellerEntity::query()->where('is_default', true)->whereNull('archived_at')->first();

        return $entity?->default_locale;
    }

    /** The plan's recurring amount in the account currency, or a best-effort 'n/a' when it is not priced there. */
    private function recurringAmount(Plan $plan, string $currency, string $locale): string
    {
        try {
            return $this->money($plan->priceFor($currency), $locale);
        } catch (Throwable) {
            return 'n/a';
        }
    }

    private function money(Money $money, string $locale): string
    {
        return MoneyFormatter::forLocale($money, $locale);
    }

    private function periodLabel(Subscription $subscription, string $locale): string
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return 'the current period';
        }

        return $this->date($start, $locale).' – '.$this->date($end, $locale);
    }

    private function date(?Carbon $date, string $locale): string
    {
        if ($date === null) {
            return 'n/a';
        }

        return $date->settings(['locale' => $locale])->translatedFormat('j M Y');
    }

    private function dateTime(?DateTimeInterface $date, string $locale): ?string
    {
        if ($date === null) {
            return null;
        }

        return CarbonImmutable::instance($date)->settings(['locale' => $locale])->translatedFormat('j M Y');
    }
}
