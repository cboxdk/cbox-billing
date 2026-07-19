<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Models\Invoice;
use App\Models\PaymentRetry;
use App\Models\Subscription;

/**
 * The smart-retry (dunning) collection surface for a failed renewal charge. It owns the
 * money-collection side of dunning — move a failed subscription to `PastDue`, retry the
 * charge on a backoff schedule, recover on success or run the terminal action on
 * exhaustion — kept behind a contract so the renewal path and the scheduled retry pass
 * depend on the behaviour, not the concrete service.
 */
interface RetriesPayments
{
    /**
     * Collect a freshly-issued renewal invoice, entering the retry flow on a hard failure.
     * A settled charge marks the invoice paid (+ receipt); a failed charge moves the
     * subscription to `PastDue` and opens the retry schedule; an out-of-band `pending`
     * result is left for the settlement webhook to resolve.
     */
    public function chargeRenewal(Invoice $invoice, Subscription $subscription): void;

    /**
     * Open (idempotently) the retry schedule for an already-failed invoice charge: move
     * the subscription to `PastDue`, record the schedule state, and send the first
     * payment-failed email. Returns the (new or existing) retry row.
     */
    public function begin(Invoice $invoice, Subscription $subscription): PaymentRetry;

    /**
     * Run one due retry attempt: re-charge the invoice; on success recover the subscription
     * to `Active` (+ receipt); on failure advance to the next scheduled attempt, or run the
     * terminal action once the schedule is exhausted. Idempotent per (invoice, attempt).
     */
    public function attempt(PaymentRetry $retry): void;

    /**
     * Operator "retry now": bring the next scheduled attempt due and run it immediately —
     * the same {@see attempt()} charge, so it stays idempotent per (invoice, attempt) and a
     * double-click never double-charges. A retry that is not `retrying` is a no-op.
     */
    public function retryNow(PaymentRetry $retry): void;

    /**
     * Operator "stop dunning": halt the retry schedule for the subscription. `$cancel`
     * chooses the terminal action — cancel the subscription immediately, or leave it
     * `PastDue`. Idempotent — a retry that is not `retrying` is left as-is.
     */
    public function stop(PaymentRetry $retry, bool $cancel): void;
}
