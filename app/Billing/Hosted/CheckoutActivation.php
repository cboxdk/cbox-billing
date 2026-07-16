<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The host effect a settled webhook applies, decorated to also ACTIVATE a hosted checkout
 * (ADR-0009 Path A). A subscription is created strictly here — on the gateway's
 * `PaymentSettled` webhook driven through the engine's exactly-once
 * {@see WebhookIngest} — never on the client-side
 * `succeeded`, and never on a `requires_action` / `processing` notice (those events are
 * not settlements, so the ingest never calls this applier for them).
 *
 * When the settled `$reference` matches a pending checkout session, the org is subscribed
 * to the session's plan and the session flipped complete; the settlement is ALSO passed to
 * the wrapped invoice applier so an ordinary invoice/renewal reference still marks its
 * invoice paid. Exactly-once is inherited from the ingest's settle-once guard on the
 * reference, so activation runs at most once even under webhook re-delivery.
 */
readonly class CheckoutActivation implements InvoicePaymentApplier
{
    public function __construct(
        private InvoicePaymentApplier $inner,
        private SubscribesOrganizations $subscriptions,
        private ManagesBillingSessions $sessions,
    ) {}

    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $this->activateCheckout($reference);

        // An ordinary invoice/renewal reference still marks its app invoice paid.
        $this->inner->markPaid($reference, $amount, $result);
    }

    private function activateCheckout(string $reference): void
    {
        $session = BillingSession::query()
            ->where('payment_reference', $reference)
            ->where('type', 'checkout')
            ->where('status', SessionStatus::Pending->value)
            ->first();

        if (! $session instanceof BillingSession) {
            return;
        }

        $organization = Organization::query()->find($session->organization_id);
        $plan = Plan::query()->with(['prices', 'product'])->where('key', $session->plan_key)->first();

        if (! $organization instanceof Organization || ! $plan instanceof Plan) {
            return;
        }

        $this->subscriptions->subscribe($organization, $plan, 1, $session->currency);
        $this->sessions->complete($session);
    }
}
