<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Coupons\Contracts\RedeemsCoupons;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Experiments\Contracts\AttributesConversions;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Mode\LivemodeScope;
use App\Billing\Payments\Exceptions\SettlementRejected;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Support\MoneyFormatter;
use App\Models\BillingSession;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
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
        private RedeemsCoupons $coupons,
        private AttributesConversions $attribution,
        private BillingContext $context,
        private RecordsAudit $audit,
    ) {}

    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $this->activateCheckout($reference, $amount);

        // An ordinary invoice/renewal reference still marks its app invoice paid.
        $this->inner->markPaid($reference, $amount, $result);
    }

    private function activateCheckout(string $reference, Money $amount): void
    {
        // The reference is globally unique, so the lookup runs WITHOUT the plane scope — the
        // webhook route carries no credential to set the mode. The matched session's own
        // `livemode` names the plane the org must be subscribed in (HP1): a test checkout
        // activates a test subscription and completes a test session, never crossing into live.
        $session = BillingSession::query()
            ->withoutGlobalScope(LivemodeScope::class)
            ->where('payment_reference', $reference)
            ->where('type', 'checkout')
            ->where('status', SessionStatus::Pending->value)
            ->first();

        if (! $session instanceof BillingSession) {
            return;
        }

        // Money integrity: the settlement must match the GROSS the intent was created for (stamped
        // on the session at intent time). A mismatch — wrong amount or currency on the settled
        // webhook — does NOT activate; it is flagged for ops (deny-by-default). A legacy session
        // with no stamped expectation is activated as before (backward-compatible).
        if (! $this->settlementMatchesExpectation($session, $amount)) {
            $this->audit->record(
                AuditAction::CheckoutSettlementRejected,
                AuditTarget::model($session, $session->organization_id),
                sprintf(
                    'Checkout settlement rejected for %s: received %s, expected %s.',
                    $session->organization_id,
                    MoneyFormatter::money($amount),
                    MoneyFormatter::minor((int) $session->expected_amount_minor, (string) $session->expected_currency),
                ),
                [
                    'received_minor' => $amount->minor(),
                    'received_currency' => $amount->currency(),
                    'expected_minor' => $session->expected_amount_minor,
                    'expected_currency' => $session->expected_currency,
                    'reference' => $reference,
                ],
                livemode: $session->livemode,
            );

            // Signal the rejection so the ingest aborts before writing the settle-once guard: a
            // corrected settlement for this checkout reference must still be able to activate.
            throw SettlementRejected::forReference($reference, sprintf(
                'Checkout settlement rejected for %s: amount/currency did not match the stamped expectation.',
                $session->organization_id,
            ));
        }

        $this->context->runInMode(BillingMode::fromLivemode($session->livemode), function () use ($session): void {
            $organization = Organization::query()->find($session->organization_id);
            $plan = Plan::query()->with(['prices', 'product'])->where('key', $session->plan_key)->first();

            if (! $organization instanceof Organization || ! $plan instanceof Plan) {
                return;
            }

            $subscription = $this->subscriptions->subscribe($organization, $plan, 1, $session->currency);
            $this->redeemCoupon($session, $subscription);
            $this->sessions->complete($session);

            // Attribute a checkout-completed conversion to the A/B variant this session carried
            // (if any). Idempotent — inherited from the ingest's settle-once guard AND the
            // unique conversion index — so a re-delivered settlement never double-counts.
            $this->attribution->recordSettlement($session);
        });
    }

    /**
     * Whether the settled `$amount` matches the session's stamped expected charge (same currency
     * AND exact minor units). A session with no stamped expectation (a legacy pre-migration row)
     * returns true so it activates as before — only a present, non-matching expectation is refused.
     */
    private function settlementMatchesExpectation(BillingSession $session, Money $amount): bool
    {
        if ($session->expected_amount_minor === null || $session->expected_currency === null) {
            return true;
        }

        return $amount->currency() === $session->expected_currency
            && $amount->minor() === $session->expected_amount_minor;
    }

    /**
     * Redeem the session's promo code against the freshly-created subscription and bind it,
     * so a repeating/forever coupon also discounts renewals (the up-front charge was already
     * discounted at intent time). Best-effort: a code that became invalid between checkout
     * and settlement (expired, exhausted by a concurrent redeemer) is skipped — the settled
     * charge stands, and this webhook must not throw.
     */
    private function redeemCoupon(BillingSession $session, Subscription $subscription): void
    {
        if ($session->coupon_code === null) {
            return;
        }

        $coupon = Coupon::query()->where('code', $session->coupon_code)->first();

        if (! $coupon instanceof Coupon) {
            return;
        }

        try {
            $this->coupons->redeem($coupon, $subscription);
        } catch (CouponRedemptionDenied) {
            // The code lapsed after checkout opened — leave the settled subscription as-is.
        }
    }
}
