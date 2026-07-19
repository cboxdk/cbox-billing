<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\BillingSession;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Illuminate\Support\Carbon;
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
 * hands back the resulting client secret. The intent's `account` is the org's gateway
 * customer id (`cus_…`), resolved once and reused via {@see ResolvesGatewayCustomer},
 * never the raw org id — so the gateway vaults against its own customer.
 */
readonly class CheckoutPaymentFlow
{
    public function __construct(
        private PaymentGateway $gateway,
        private ResolvesAccountCurrency $currencies,
        private ResolvesGatewayCustomer $customers,
        private CouponDiscounter $coupons,
    ) {}

    public function intent(BillingSession $session): PaymentIntentResult
    {
        $organization = $this->organization($session);
        $plan = $this->plan($session);
        $currency = $session->currency ?? $this->currencies->for($organization);
        $amount = $this->amount($session, $plan, $currency);

        $reference = $this->stampReference($session);

        return $this->gateway->createPaymentIntent(new PaymentIntentRequest(
            account: $this->customers->resolve($organization),
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

    /** The amount actually charged, net of the session's promo code — what the page displays. */
    public function price(BillingSession $session): Money
    {
        $plan = $this->plan($session);
        $currency = $this->currency($session);

        return $this->amount($session, $plan, $currency);
    }

    /**
     * The plan price for this session's charge, discounted by its promo code through the
     * engine applier ({@see CouponDiscounter}) when one is set and still valid — so the
     * gateway charges exactly the discounted amount the page shows. An invalid/expired code
     * is a no-op (full price), deny-by-default.
     */
    private function amount(BillingSession $session, Plan $plan, string $currency): Money
    {
        $full = $plan->priceFor($currency);
        $coupon = $this->coupon($session);

        if (! $coupon instanceof Coupon) {
            return $full;
        }

        $discount = $this->coupons->forCoupon($coupon, $full, Carbon::now()->toDateTimeImmutable());

        return $discount === null ? $full : $discount->discounted;
    }

    /** The session's promo coupon, or null when it carries none / an unknown code. */
    private function coupon(BillingSession $session): ?Coupon
    {
        if ($session->coupon_code === null) {
            return null;
        }

        return Coupon::query()->where('code', $session->coupon_code)->first();
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
