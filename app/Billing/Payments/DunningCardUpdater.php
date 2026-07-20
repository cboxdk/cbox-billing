<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Mode\BillingContext;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Dunning\CardUpdate;
use App\Billing\Payments\Dunning\CardUpdateResult;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use App\Models\PaymentRetryAttempt;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The working card / account-updater: applies a gateway card-update to the account's vaulted
 * default method and immediately re-attempts the account's in-dunning charges, so a fresh card
 * recovers the payment without waiting for the next scheduled retry (or, for a hard decline
 * that stopped the schedule, at all).
 *
 * WHAT IT DOES:
 *  1. Resolves the account key to an organization (a stored {@see GatewayCustomer} mapping, or
 *     the account key used directly as the org id for the manual/host gateway).
 *  2. Points the gateway's default method at the updated token (best-effort — a vault-less
 *     manual gateway is a no-op; a gateway hiccup never fails the recovery).
 *  3. Re-attempts every recovery candidate for the account: any charge still `retrying`, and any
 *     hard-declined charge whose subscription was left serving/past-due (terminal action `none`)
 *     with the invoice still open — the exact case a new card resolves. Each is driven through
 *     the SAME idempotent {@see RetriesPayments::retryNow}, so this can never double-charge.
 */
readonly class DunningCardUpdater implements UpdatesCards
{
    public function __construct(
        private RetriesPayments $retries,
        private PaymentGateway $gateway,
        private LoggerInterface $log,
        private BillingContext $context,
        private WebhookPlaneResolver $planes,
    ) {}

    public function apply(CardUpdate $update): CardUpdateResult
    {
        // HP1: the card-updater webhook carries no credential to set the plane, so resolve the
        // gateway customer's OWN plane (UNSCOPED, via the shared {@see WebhookPlaneResolver} — the
        // same seam the controller uses to pick the verification secret's plane) and adopt it before
        // any of the mode-scoped lookups below (org mapping, in-dunning retries, the vaulted default)
        // run — a sandbox card-update then recovers only that environment's charges, and vice-versa.
        // With no gateway customer (the manual/host gateway keys the vault by org id directly) there
        // is no plane signal, so we stay in the ambient plane.
        $plane = $this->planes->forAccount($update->gateway, $update->account);

        return $this->context->runInEnvironment($plane, fn (): CardUpdateResult => $this->applyInPlane($update));
    }

    private function applyInPlane(CardUpdate $update): CardUpdateResult
    {
        $organizationId = $this->resolveOrganization($update);

        if ($organizationId === null) {
            return CardUpdateResult::ignored('no account matched the card-update');
        }

        $this->pointDefaultAtUpdatedMethod($update, $organizationId);

        $candidates = $this->candidates($organizationId);

        if ($candidates === []) {
            return CardUpdateResult::applied($organizationId, 0, 0);
        }

        $reattempted = 0;

        foreach ($candidates as $retry) {
            $this->reopenIfHardStopped($retry);
            $this->noteCardUpdate($retry, $update);
            $this->retries->retryNow($retry);
            $reattempted++;
        }

        // Count how many of the touched retries ended up recovered (the invoice settled).
        $recovered = PaymentRetry::query()
            ->whereIn('id', array_map(static fn (PaymentRetry $r): int => $r->id, $candidates))
            ->where('status', PaymentRetry::STATUS_RECOVERED)
            ->count();

        return CardUpdateResult::applied($organizationId, $reattempted, $recovered);
    }

    /** Map the update's account key to an organization id, or null when nothing matches. */
    private function resolveOrganization(CardUpdate $update): ?string
    {
        $mapping = GatewayCustomer::query()
            ->where('gateway', $update->gateway)
            ->where('gateway_customer_id', $update->account)
            ->first();

        if ($mapping instanceof GatewayCustomer) {
            return $mapping->organization_id;
        }

        // The manual/host gateway keys the vault by the account (organization) id directly.
        return Organization::query()->whereKey($update->account)->exists() ? $update->account : null;
    }

    /** Best-effort: make the updated token the account's default off-session method. */
    private function pointDefaultAtUpdatedMethod(CardUpdate $update, string $organizationId): void
    {
        if ($this->gateway->name() === 'manual') {
            return; // A vault-less gateway has no default to move.
        }

        $customerId = GatewayCustomer::query()
            ->where('gateway', $update->gateway)
            ->where('organization_id', $organizationId)
            ->value('gateway_customer_id');

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        try {
            $this->gateway->setDefaultPaymentMethod($customerId, $update->paymentMethodId);
        } catch (Throwable $e) {
            // A gateway hiccup must never block the recovery re-attempt — the charge below is
            // the source of truth for whether the new method works.
            $this->log->warning('Card-update: could not set default payment method.', [
                'organization' => $organizationId,
                'gateway' => $update->gateway,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The account's recovery candidates: every `retrying` charge, plus any hard-declined charge
     * whose subscription is still recoverable (serving/past-due, invoice open) — the case a new
     * card is meant to fix.
     *
     * @return list<PaymentRetry>
     */
    private function candidates(string $organizationId): array
    {
        $retries = PaymentRetry::query()
            ->with(['invoice', 'subscription'])
            ->where('organization_id', $organizationId)
            ->whereIn('status', [PaymentRetry::STATUS_RETRYING, PaymentRetry::STATUS_EXHAUSTED])
            ->orderBy('id')
            ->get();

        $candidates = [];

        foreach ($retries as $retry) {
            if ($retry->status === PaymentRetry::STATUS_RETRYING) {
                $candidates[] = $retry;

                continue;
            }

            // An exhausted (typically hard-declined) charge is a candidate only when the
            // subscription was NOT canceled and the invoice is still open — otherwise a new card
            // cannot un-cancel it.
            if ($this->isRecoverableAfterHardStop($retry)) {
                $candidates[] = $retry;
            }
        }

        return $candidates;
    }

    private function isRecoverableAfterHardStop(PaymentRetry $retry): bool
    {
        $subscription = $retry->subscription;
        $invoice = $retry->invoice;

        return $subscription instanceof Subscription
            && $invoice instanceof Invoice
            && ! $subscription->isCanceled()
            && $subscription->isServing()
            && ! $invoice->isPaid();
    }

    /**
     * Re-open a hard-stopped schedule for one more attempt with the fresh card. `next_attempt_at`
     * is left null — {@see RetriesPayments::retryNow} brings it due on the (clock-driven) now, so
     * timing stays consistent with the test clock.
     */
    private function reopenIfHardStopped(PaymentRetry $retry): void
    {
        if ($retry->isRetrying()) {
            return;
        }

        $retry->forceFill([
            'status' => PaymentRetry::STATUS_RETRYING,
            'max_attempts' => max($retry->max_attempts, $retry->attempts + 1),
            'next_attempt_at' => null,
        ])->save();
    }

    /** Record the card-update event on the retry's timeline before the re-attempt. */
    private function noteCardUpdate(PaymentRetry $retry, CardUpdate $update): void
    {
        $card = $update->brand !== null && $update->last4 !== null
            ? ' ('.$update->brand.' ····'.$update->last4.')'
            : '';

        PaymentRetryAttempt::query()->create([
            'payment_retry_id' => $retry->id,
            'attempt' => $retry->attempts,
            'outcome' => PaymentRetryAttempt::OUTCOME_CARD_UPDATED,
            'decline_code' => null,
            'decline_category' => $retry->decline_category,
            'gateway_reference' => $update->paymentMethodId,
            'detail' => 'Card updated'.$card.' via '.$update->source.' — re-attempting the charge now.',
            'next_attempt_at' => null,
        ]);
    }
}
