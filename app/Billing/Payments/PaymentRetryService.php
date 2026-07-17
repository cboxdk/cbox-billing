<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Jobs\RunOrgDunningJob;
use App\Models\Invoice;
use App\Models\PaymentRetry;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * Smart-retry dunning for a FAILED renewal charge (the money-collection counterpart to the
 * access-gating {@see RunOrgDunningJob}). The state machine it drives:
 *
 *   charge fails  → subscription {@see SubscriptionStatus::PastDue}
 *                   + a {@see PaymentRetry} row scheduling the first backoff attempt
 *   retry settles → invoice marked paid (+ receipt), subscription recovered to `Active`
 *   retry fails   → advance to the next scheduled attempt (payment-failed email each time)
 *   exhausted     → the terminal action (immediate cancel, or leave `PastDue`)
 *
 * Every scheduled offset is driven off `first_failed_at`, so the cadence is stable
 * regardless of when the pass happens to run. Idempotency is per (invoice, attempt): an
 * attempt is CLAIMED with a conditional `attempts` bump before the charge, so an
 * overlapping or re-dispatched pass can never fire the same attempt twice.
 */
readonly class PaymentRetryService implements RetriesPayments
{
    public function __construct(
        private PaysInvoices $payments,
        private InvoicePaymentApplier $applier,
        private SubscribesOrganizations $subscriptions,
        private NotifiesCustomers $notifier,
        private Config $config,
    ) {}

    public function chargeRenewal(Invoice $invoice, Subscription $subscription): void
    {
        // A renewal that already settled (or has no balance) needs no collection.
        if ($invoice->isPaid()) {
            return;
        }

        $result = $this->payments->pay($invoice);

        if ($result->isSettled()) {
            $this->settle($invoice, $result);

            return;
        }

        // A hard decline opens the retry schedule. A `pending` / `requires_action` result
        // is an out-of-band settlement (bank transfer, manual capture) — the settlement
        // webhook resolves it, so we do NOT treat it as a failure here.
        if ($result->status === PaymentStatus::Failed) {
            $this->begin($invoice, $subscription);
        }
    }

    public function begin(Invoice $invoice, Subscription $subscription): PaymentRetry
    {
        $existing = PaymentRetry::query()->where('invoice_id', $invoice->id)->first();

        if ($existing instanceof PaymentRetry) {
            return $existing;
        }

        $schedule = $this->schedule();
        $now = Carbon::now();

        // Move the subscription into the engine's PastDue state — it keeps serving while
        // the charge is chased.
        $this->subscriptions->markPastDue($subscription);

        $retry = PaymentRetry::query()->create([
            'invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'subscription_id' => $subscription->id,
            'attempts' => 0,
            'max_attempts' => count($schedule),
            'status' => PaymentRetry::STATUS_RETRYING,
            'first_failed_at' => $now,
            'next_attempt_at' => $now->copy()->addDays($schedule[0]),
        ]);

        // The initial failure notice (attempt 0), so the customer is warned before the
        // first automated retry.
        $this->notifier->paymentRetryFailed(
            $subscription,
            $invoice,
            attempt: 0,
            maxAttempts: $retry->max_attempts,
            nextAttemptAt: $retry->next_attempt_at,
            exhausted: false,
        );

        return $retry;
    }

    public function attempt(PaymentRetry $retry): void
    {
        $now = Carbon::now();

        if (! $retry->isRetrying() || $retry->next_attempt_at === null || $retry->next_attempt_at->greaterThan($now)) {
            return;
        }

        $invoice = $retry->invoice()->with('organization')->first();
        $subscription = $retry->subscription()->with(['organization', 'plan'])->first();

        if (! $invoice instanceof Invoice || ! $subscription instanceof Subscription) {
            return;
        }

        // Settled out of band since the last pass (a webhook landed): recover and close,
        // no re-charge.
        if ($invoice->isPaid()) {
            $this->markRecovered($retry, $subscription, null, $now);

            return;
        }

        // Claim THIS attempt atomically: only the worker that bumps `attempts` from its
        // observed value proceeds, so a concurrent/re-dispatched pass never double-charges.
        $attemptNumber = $retry->attempts + 1;
        $claimed = PaymentRetry::query()
            ->where('id', $retry->id)
            ->where('status', PaymentRetry::STATUS_RETRYING)
            ->where('attempts', $retry->attempts)
            ->update(['attempts' => $attemptNumber, 'last_attempt_at' => $now]);

        if ($claimed === 0) {
            return;
        }

        $result = $this->payments->pay($invoice);

        if ($result->isSettled()) {
            $this->settle($invoice, $result);
            $this->markRecovered($retry, $subscription, $result->gatewayReference, $now);

            return;
        }

        if ($attemptNumber >= $retry->max_attempts) {
            $retry->forceFill([
                'status' => PaymentRetry::STATUS_EXHAUSTED,
                'next_attempt_at' => null,
                'last_reference' => $result->gatewayReference,
            ])->save();

            $this->runTerminalAction($subscription);

            $this->notifier->paymentRetryFailed($subscription, $invoice, $attemptNumber, $retry->max_attempts, null, exhausted: true);

            return;
        }

        // Schedule the next attempt off the ORIGINAL failure instant, so the cadence is
        // stable regardless of when this pass ran.
        $schedule = $this->schedule();
        $nextAt = $retry->first_failed_at->copy()->addDays($schedule[$attemptNumber]);

        $retry->forceFill([
            'next_attempt_at' => $nextAt,
            'last_reference' => $result->gatewayReference,
        ])->save();

        $this->notifier->paymentRetryFailed($subscription, $invoice, $attemptNumber, $retry->max_attempts, $nextAt, exhausted: false);
    }

    /** Apply the settled charge to the invoice (marks it paid and queues the receipt). */
    private function settle(Invoice $invoice, PaymentResult $result): void
    {
        $this->applier->markPaid($invoice->number, $invoice->total(), $result);
    }

    /** Recover the subscription to `Active` and close the retry row. */
    private function markRecovered(PaymentRetry $retry, Subscription $subscription, ?string $reference, Carbon $now): void
    {
        if ($subscription->isPastDue()) {
            $this->subscriptions->recover($subscription);
        }

        $retry->forceFill([
            'status' => PaymentRetry::STATUS_RECOVERED,
            'next_attempt_at' => null,
            'last_attempt_at' => $now,
            'last_reference' => $reference ?? $retry->last_reference,
        ])->save();
    }

    /** The exhaustion outcome: cancel immediately, or leave the subscription PastDue. */
    private function runTerminalAction(Subscription $subscription): void
    {
        $action = $this->config->get('billing.payment.retry.terminal_action', 'cancel');

        if ($action === 'cancel') {
            $this->subscriptions->cancel($subscription, atPeriodEnd: false);
        }
    }

    /**
     * The backoff schedule as day-offsets from the initial failure. Falls back to a single
     * next-day attempt when misconfigured, so the flow is never left with no schedule.
     *
     * @return non-empty-list<int>
     */
    private function schedule(): array
    {
        $configured = $this->config->get('billing.payment.retry.schedule', [1, 3, 5, 7]);

        $days = [];

        foreach (is_array($configured) ? $configured : [] as $offset) {
            if (is_numeric($offset) && (int) $offset > 0) {
                $days[] = (int) $offset;
            }
        }

        return $days === [] ? [1] : $days;
    }
}
