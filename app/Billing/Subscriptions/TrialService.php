<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\ConvertsTrials;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Converts due free trials (Part 3). It composes the engine's `Trialing` → `Active`
 * transition (via {@see SubscribesOrganizations::convertTrial()}) with this app's
 * first-charge invoicing and collection:
 *
 *  - a due trial WITH collection ready → convert to `Active`, raise the first invoice, and
 *    collect it through the ordinary charge/retry path (a hard decline enters smart-retry);
 *  - a due trial with NO payment method, when one is required → the configured
 *    no-payment-method action (cancel — deny-by-default — or pause so the customer can add
 *    a method and resume).
 *
 * A non-`Trialing`, paused, or not-yet-due subscription is a no-op, so the scheduled pass
 * is idempotent: once converted, the subscription is `Active` and never re-selected.
 */
readonly class TrialService implements ConvertsTrials
{
    public function __construct(
        private SubscribesOrganizations $subscriptions,
        private ManagesSubscriptionDepth $depth,
        private GeneratesInvoices $invoices,
        private RetriesPayments $retries,
        private ResolvesGatewayCustomer $customers,
        private PaymentGateway $gateway,
        private Config $config,
        private BillingClock $clock,
    ) {}

    public function convertDue(Subscription $subscription, ?DateTimeImmutable $at = null): string
    {
        $now = $at ?? $this->clock->now();

        // Deny-by-default: only an unpaused, actually-due trial converts.
        if (! $subscription->isTrialing() || $subscription->isPaused()) {
            return self::OUTCOME_SKIPPED;
        }

        $trialEndsAt = $subscription->trial_ends_at;

        if ($trialEndsAt === null || $trialEndsAt->toDateTimeImmutable() > $now) {
            return self::OUTCOME_SKIPPED;
        }

        // When a payment method is required and none is on file, take the configured action
        // instead of charging into a wall.
        if ($this->requiresPaymentMethod() && ! $this->hasPaymentMethod($subscription)) {
            return $this->handleNoPaymentMethod($subscription);
        }

        // Convert Trialing → Active (the engine transition), then raise + collect the first
        // charge. The subscription is converted even if invoicing is deferred (tax-pending):
        // the invoice/renewal pass catches up, exactly as it does for an ordinary renewal.
        $this->subscriptions->convertTrial($subscription);

        $this->firstCharge($subscription->refresh()->loadMissing('organization', 'plan'));

        return self::OUTCOME_CONVERTED;
    }

    /** Raise the first invoice for a just-converted trial and collect it. */
    private function firstCharge(Subscription $subscription): void
    {
        try {
            $invoice = $this->invoices->generate($subscription);
        } catch (RuntimeException) {
            // Tax-pending (no billing address) — not chargeable yet; the subscription is
            // already Active, and the monthly invoice pass will issue it once resolvable.
            return;
        }

        $this->retries->chargeRenewal($invoice, $subscription);
    }

    /** Cancel or pause a due trial that has no payment method, per configuration. */
    private function handleNoPaymentMethod(Subscription $subscription): string
    {
        $action = $this->config->get('billing.trial.no_payment_method_action', 'cancel');

        if ($action === 'pause') {
            $this->depth->pause($subscription);

            return self::OUTCOME_PAUSED;
        }

        $this->subscriptions->cancel($subscription, atPeriodEnd: false);

        return self::OUTCOME_CANCELED;
    }

    private function requiresPaymentMethod(): bool
    {
        return (bool) $this->config->get('billing.trial.require_payment_method', false);
    }

    /** Whether the org has a vaulted method the conversion charge could be billed against. */
    private function hasPaymentMethod(Subscription $subscription): bool
    {
        $organization = $subscription->organization;

        if (! $organization instanceof Organization) {
            return false;
        }

        return $this->gateway->paymentMethods($this->customers->resolve($organization)) !== [];
    }
}
