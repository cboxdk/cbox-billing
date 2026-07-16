<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the ON-SESSION PaymentIntent a hosted checkout mounts its gateway element with.
 * The amount is the session's plan priced in the account's currency (never a re-derived
 * float — the engine's {@see Money} value object), and the intent's
 * `reference`/`idempotencyKey` is the session's stable `payment_reference`, minted once so
 * a double-submit or a network retry collapses to a single gateway intent — and so the
 * later settled webhook can join back to this session to activate it.
 *
 * Card data and any SCA / 3-D Secure challenge happen on the gateway's element, never
 * here; the engine only asks the bound {@see PaymentGateway} to create the intent and
 * hands back the resulting client secret.
 */
readonly class CheckoutPaymentFlow
{
    public function __construct(
        private PaymentGateway $gateway,
        private ResolvesAccountCurrency $currencies,
    ) {}

    public function intent(BillingSession $session): PaymentIntentResult
    {
        $organization = $this->organization($session);
        $plan = $this->plan($session);
        $currency = $session->currency ?? $this->currencies->for($organization);
        $amount = $plan->priceFor($currency);

        $reference = $this->stampReference($session);

        return $this->gateway->createPaymentIntent(new PaymentIntentRequest(
            account: $organization->id,
            reference: $reference,
            amount: $amount,
            idempotencyKey: $reference,
        ));
    }

    /** The plan the session is collecting payment for, presentable to the page. */
    public function plan(BillingSession $session): Plan
    {
        $plan = Plan::query()->with(['prices', 'product'])->where('key', $session->plan_key)->first();

        if (! $plan instanceof Plan) {
            throw new RuntimeException(sprintf('Checkout session [%s] references an unknown plan.', $session->id));
        }

        return $plan;
    }

    /** The currency this session's charge is priced in. */
    public function currency(BillingSession $session): string
    {
        return $session->currency ?? $this->currencies->for($this->organization($session));
    }

    private function organization(BillingSession $session): Organization
    {
        $organization = Organization::query()->find($session->organization_id);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(sprintf('Checkout session [%s] references an unknown organization.', $session->id));
        }

        return $organization;
    }

    /**
     * The session's stable settlement reference, minted once. Reusing it across retries
     * keeps the gateway idempotency key stable and lets the settled webhook find the
     * session to activate.
     */
    private function stampReference(BillingSession $session): string
    {
        if (is_string($session->payment_reference) && $session->payment_reference !== '') {
            return $session->payment_reference;
        }

        $reference = 'chk_'.Str::random(24);
        $session->forceFill(['payment_reference' => $reference])->save();

        return $reference;
    }
}
