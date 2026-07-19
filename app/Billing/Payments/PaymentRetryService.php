<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Payments\Contracts\ClassifiesDeclines;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\DeclineOutcome;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Jobs\RunOrgDunningJob;
use App\Models\Invoice;
use App\Models\PaymentRetry;
use App\Models\PaymentRetryAttempt;
use App\Models\Subscription;
use App\Webhooks\Events\DunningExhausted as DunningExhaustedEvent;
use App\Webhooks\Events\PaymentFailed as PaymentFailedEvent;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * ADAPTIVE smart-retry dunning for a FAILED renewal charge (the money-collection counterpart to
 * the access-gating {@see RunOrgDunningJob}). Where the old flow retried every failure on one
 * fixed `[1,3,5,7]` schedule, this classifies each decline ({@see ClassifiesDeclines}) and lets
 * the outcome drive recovery ({@see SchedulesRetries}):
 *
 *   charge fails  → classify the gateway decline code into a recovery category
 *     · HARD (lost/stolen/closed/expired/fraud) → the schedule is NOT opened: short-circuit to
 *       the terminal action + a "update your payment method" notice (+ a retention save-offer).
 *       A later card-updater push (see {@see DunningCardUpdater}) can still
 *       recover it.
 *     · RECOVERABLE / INSUFFICIENT-FUNDS / TRY-AGAIN-LATER / NEEDS-ACTION → subscription
 *       {@see SubscriptionStatus::PastDue} + a {@see PaymentRetry} scheduling the first attempt
 *       on that category's adaptive curve (spread, payday-aware, weekend-avoiding, window-bound).
 *   retry settles → invoice paid (+ receipt), subscription recovered to `Active`.
 *   retry fails   → RE-CLASSIFY (a decline can escalate soft→hard mid-flight) and advance to the
 *                   next scheduled attempt on the current category's curve.
 *   exhausted     → the terminal action (immediate cancel, or leave `PastDue`).
 *
 * Every step appends to the {@see PaymentRetryAttempt} timeline and sends a decline-category-
 * tailored email. Every scheduled instant is a pure function of `first_failed_at` + the plan, so
 * the cadence is stable and a test clock drives it exactly. Idempotency is per (invoice,
 * attempt): an attempt is CLAIMED with a conditional `attempts` bump before the charge, so an
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
        private BillingClock $clock,
        private ClassifiesDeclines $classifier,
        private SchedulesRetries $strategy,
        private RetentionOffers $offers,
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

        // A hard decline opens the (adaptive) retry flow. A `pending` / `requires_action` result
        // is an out-of-band settlement (bank transfer, manual capture) — the settlement
        // webhook resolves it, so we do NOT treat it as a failure here.
        if ($result->status === PaymentStatus::Failed) {
            $this->open($invoice, $subscription, $this->classifier->classify($result), $result->gatewayReference);
        }
    }

    public function begin(Invoice $invoice, Subscription $subscription): PaymentRetry
    {
        // The contract entry with no PaymentResult to classify (an already-failed charge opened
        // by a caller): default to Unknown, which rides the base curve.
        return $this->open($invoice, $subscription, new DeclineOutcome('unknown', DeclineCategory::Unknown), null);
    }

    public function attempt(PaymentRetry $retry): void
    {
        $nowImmutable = $this->clock->now();
        $now = Carbon::instance($nowImmutable);

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

        // RE-CLASSIFY: a decline can change category between attempts (a soft decline can escalate
        // to a hard one), so the NEXT step is chosen off the current decline, not the first one.
        $decline = $this->classifier->classify($result);
        $plan = $this->strategy->planFor($decline->category);

        $next = $decline->category->isRecoverable() && $attemptNumber < $plan->maxAttempts
            ? $this->strategy->attemptAt($decline->category, $attemptNumber + 1, $retry->first_failed_at->toImmutable())
            : null;

        // Terminal: a mid-flight hard decline, the (possibly re-derived) ceiling reached, or the
        // next instant would fall outside the recovery window.
        if (! $decline->category->isRecoverable() || $next === null) {
            $this->exhaust($retry, $subscription, $invoice, $attemptNumber, $decline, $result->gatewayReference);

            return;
        }

        $nextAt = Carbon::instance($next);

        $retry->forceFill([
            'next_attempt_at' => $nextAt,
            'decline_code' => $decline->code,
            'decline_category' => $decline->category->value,
            'max_attempts' => $plan->maxAttempts,
            'last_reference' => $result->gatewayReference,
        ])->save();

        $timelineOutcome = $decline->category === DeclineCategory::NeedsAction
            ? PaymentRetryAttempt::OUTCOME_AUTHENTICATE
            : PaymentRetryAttempt::OUTCOME_FAILED;

        $this->logAttempt($retry, $attemptNumber, $timelineOutcome, $decline, $result->gatewayReference, $this->scheduleDetail($decline, $nextAt), $nextAt);

        $this->notifier->paymentRetryFailed($subscription, $invoice, $attemptNumber, $plan->maxAttempts, $nextAt, exhausted: false, category: $decline->category);

        event(new PaymentFailedEvent($subscription, $invoice, $attemptNumber, $plan->maxAttempts, $nextAt, $result->gatewayReference));
    }

    public function retryNow(PaymentRetry $retry): void
    {
        if (! $retry->isRetrying()) {
            return;
        }

        // Bring the next slot due, then run the ordinary attempt — the per-(invoice,
        // attempt) claim in attempt() keeps it idempotent, so a double click is safe.
        if ($retry->next_attempt_at === null || $retry->next_attempt_at->isFuture()) {
            $retry->forceFill(['next_attempt_at' => Carbon::instance($this->clock->now())])->save();
        }

        $this->attempt($retry->refresh());
    }

    public function stop(PaymentRetry $retry, bool $cancel): void
    {
        if (! $retry->isRetrying()) {
            return;
        }

        $retry->forceFill([
            'status' => PaymentRetry::STATUS_STOPPED,
            'next_attempt_at' => null,
        ])->save();

        $this->logAttempt(
            $retry,
            $retry->attempts,
            PaymentRetryAttempt::OUTCOME_STOPPED,
            null,
            null,
            $cancel ? 'Operator stopped dunning and canceled the subscription.' : 'Operator stopped dunning; subscription left past due.',
            null,
        );

        if (! $cancel) {
            return; // the subscription is left PastDue (the schedule is simply halted).
        }

        $subscription = $retry->subscription()->with(['organization', 'plan'])->first();

        if ($subscription instanceof Subscription) {
            $this->subscriptions->cancel($subscription, atPeriodEnd: false);
        }
    }

    /**
     * Open the recovery flow for a failed charge, classified into `$decline`. A HARD decline
     * short-circuits (no schedule); every recoverable category opens the adaptive schedule.
     * Idempotent — an invoice already under retry returns its existing row.
     */
    private function open(Invoice $invoice, Subscription $subscription, DeclineOutcome $decline, ?string $reference): PaymentRetry
    {
        $existing = PaymentRetry::query()->where('invoice_id', $invoice->id)->first();

        if ($existing instanceof PaymentRetry) {
            return $existing;
        }

        $nowImmutable = $this->clock->now();
        $now = Carbon::instance($nowImmutable);

        // Move the subscription into the engine's PastDue state — it keeps serving while
        // the charge is chased (or, for a hard decline, until the terminal action fires).
        $this->subscriptions->markPastDue($subscription);

        // Deep integration: surface the bound retention seam's top save-offer on entry, recorded
        // for the console + the dunning email (inert when no offer is configured).
        $offer = $this->topOffer($subscription);

        if (! $decline->category->isRecoverable()) {
            return $this->openHard($invoice, $subscription, $decline, $reference, $offer, $now);
        }

        $plan = $this->strategy->planFor($decline->category);
        $next = $this->strategy->attemptAt($decline->category, 1, $nowImmutable);
        $nextAt = $next !== null ? Carbon::instance($next) : null;

        $retry = PaymentRetry::query()->create([
            'invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'subscription_id' => $subscription->id,
            'attempts' => 0,
            'max_attempts' => $plan->maxAttempts,
            'status' => PaymentRetry::STATUS_RETRYING,
            'decline_code' => $decline->code,
            'decline_category' => $decline->category->value,
            'save_offer_key' => $offer?->key,
            'save_offer_label' => $offer?->label,
            'first_failed_at' => $now,
            'next_attempt_at' => $nextAt,
            'last_reference' => $reference,
        ]);

        $openOutcome = $decline->category === DeclineCategory::NeedsAction
            ? PaymentRetryAttempt::OUTCOME_AUTHENTICATE
            : PaymentRetryAttempt::OUTCOME_FAILED;

        $this->logAttempt($retry, 0, $openOutcome, $decline, $reference, $this->openDetail($decline, $nextAt), $nextAt);

        // The initial failure notice (attempt 0), tailored to the decline category, so the
        // customer is warned before the first automated retry.
        $this->notifier->paymentRetryFailed($subscription, $invoice, 0, $plan->maxAttempts, $nextAt, exhausted: false, category: $decline->category);

        return $retry;
    }

    /** A hard decline: record it, run the terminal action, ask for a new method — no retries. */
    private function openHard(Invoice $invoice, Subscription $subscription, DeclineOutcome $decline, ?string $reference, ?SaveOffer $offer, Carbon $now): PaymentRetry
    {
        $retry = PaymentRetry::query()->create([
            'invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'subscription_id' => $subscription->id,
            'attempts' => 0,
            'max_attempts' => 0,
            'status' => PaymentRetry::STATUS_EXHAUSTED,
            'decline_code' => $decline->code,
            'decline_category' => $decline->category->value,
            'save_offer_key' => $offer?->key,
            'save_offer_label' => $offer?->label,
            'first_failed_at' => $now,
            'next_attempt_at' => null,
            'last_reference' => $reference,
        ]);

        $this->logAttempt(
            $retry,
            0,
            PaymentRetryAttempt::OUTCOME_EXHAUSTED,
            $decline,
            $reference,
            'Hard decline ('.$decline->code.') — a new payment method is required; no automatic retries.',
            null,
        );

        $this->runTerminalAction($subscription);

        $this->notifier->paymentRetryFailed($subscription, $invoice, 0, 0, null, exhausted: true, category: $decline->category);

        event(new DunningExhaustedEvent($subscription, $invoice, 0));

        return $retry;
    }

    /** Close the schedule as exhausted (or hard mid-flight) and run the terminal action. */
    private function exhaust(PaymentRetry $retry, Subscription $subscription, Invoice $invoice, int $attemptNumber, DeclineOutcome $decline, ?string $reference): void
    {
        $retry->forceFill([
            'status' => PaymentRetry::STATUS_EXHAUSTED,
            'next_attempt_at' => null,
            'decline_code' => $decline->code,
            'decline_category' => $decline->category->value,
            'max_attempts' => max($retry->max_attempts, $attemptNumber),
            'last_reference' => $reference,
        ])->save();

        $detail = $decline->category->isRecoverable()
            ? 'Retry schedule exhausted after '.$attemptNumber.' attempt'.($attemptNumber === 1 ? '' : 's').'.'
            : 'Hard decline ('.$decline->code.') — a new payment method is required.';

        $this->logAttempt($retry, $attemptNumber, PaymentRetryAttempt::OUTCOME_EXHAUSTED, $decline, $reference, $detail, null);

        $this->runTerminalAction($subscription);

        $this->notifier->paymentRetryFailed($subscription, $invoice, $attemptNumber, $retry->max_attempts, null, exhausted: true, category: $decline->category);

        event(new DunningExhaustedEvent($subscription, $invoice, $attemptNumber));
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

        $this->logAttempt(
            $retry,
            $retry->attempts,
            PaymentRetryAttempt::OUTCOME_RECOVERED,
            null,
            $reference ?? $retry->last_reference,
            'Payment recovered — invoice settled and the subscription reactivated.',
            null,
        );
    }

    /** The exhaustion outcome: cancel immediately, or leave the subscription PastDue. */
    private function runTerminalAction(Subscription $subscription): void
    {
        $action = $this->config->get('billing.payment.retry.terminal_action', 'cancel');

        if ($action === 'cancel') {
            $this->subscriptions->cancel($subscription, atPeriodEnd: false);
        }
    }

    /** The bound retention seam's top save-offer for this subscription (null when none). */
    private function topOffer(Subscription $subscription): ?SaveOffer
    {
        $offers = $this->offers->offersFor($subscription->organization_id, (string) $subscription->id);

        return $offers[0] ?? null;
    }

    /** Append one event to the smart-retry's attempts timeline. */
    private function logAttempt(PaymentRetry $retry, int $attempt, string $outcome, ?DeclineOutcome $decline, ?string $reference, ?string $detail, ?Carbon $next): void
    {
        PaymentRetryAttempt::query()->create([
            'payment_retry_id' => $retry->id,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'decline_code' => $decline?->code,
            'decline_category' => $decline?->category->value,
            'gateway_reference' => $reference,
            'detail' => $detail,
            'next_attempt_at' => $next,
        ]);
    }

    /** The timeline note for the initial failure (attempt 0). */
    private function openDetail(DeclineOutcome $decline, ?Carbon $next): string
    {
        $label = $decline->category->label();

        if ($decline->category === DeclineCategory::NeedsAction) {
            return $label.' ('.$decline->code.') — authenticate link sent'.($next !== null ? '; retry '.$next->format('Y-m-d') : '').'.';
        }

        return $label.' ('.$decline->code.') — first failure'.($next !== null ? '; next retry '.$next->format('Y-m-d') : '').'.';
    }

    /** The timeline note for a failed retry that reschedules. */
    private function scheduleDetail(DeclineOutcome $decline, Carbon $next): string
    {
        return $decline->category->label().' ('.$decline->code.') — next retry '.$next->format('Y-m-d').'.';
    }
}
