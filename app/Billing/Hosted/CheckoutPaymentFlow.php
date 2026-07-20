<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Coupons\Contracts\DiscountsAmounts;
use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Tax\TaxContextFactory;
use App\Models\BillingSession;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
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
 *
 * The charged amount is the tax-aware GROSS: the plan price (less any promo) is run through
 * the same engine {@see QuoteBuilder} + app {@see TaxContextFactory} the invoice path uses, so
 * a taxable customer is charged VAT-inclusive gross exactly as the first invoice would bill —
 * never bare net. The page displays the same gross (preview == charge).
 */
readonly class CheckoutPaymentFlow
{
    public function __construct(
        private PaymentGateway $gateway,
        private ResolvesAccountCurrency $currencies,
        private ResolvesGatewayCustomer $customers,
        private DiscountsAmounts $coupons,
        private QuoteBuilder $quotes,
        private TaxContextFactory $taxContexts,
    ) {}

    public function intent(BillingSession $session): PaymentIntentResult
    {
        $organization = $this->organization($session);
        $plan = $this->plan($session);
        $currency = $session->currency ?? $this->currencies->for($organization);
        $amount = $this->amount($session, $plan, $currency);

        $reference = $this->stampReference($session, $amount);

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

    /**
     * The tax-aware GROSS actually charged, net of the session's promo code — what the page
     * displays. Preview == charge by construction: {@see intent()} charges this exact figure.
     */
    public function price(BillingSession $session): Money
    {
        $plan = $this->plan($session);
        $currency = $this->currency($session);

        return $this->amount($session, $plan, $currency);
    }

    /**
     * The undiscounted list price as GROSS (the same tax context), for the struck-through
     * "before" amount when a promo reduced the charge. Equal to {@see price()} when no promo
     * applies, so the page shows no strikethrough.
     */
    public function listPrice(BillingSession $session): Money
    {
        $plan = $this->plan($session);
        $currency = $this->currency($session);

        return $this->grossOf($this->organization($session), [
            new LineInput($plan->name, 1, $plan->amountFor($currency, 1)),
        ]);
    }

    /**
     * The tax-aware GROSS this session's charge collects: the plan price discounted by its
     * promo code through the engine applier ({@see CouponDiscounter}) when one is set and still
     * valid, then taxed for the org's place of supply through the same {@see QuoteBuilder} the
     * invoice path uses — so the gateway charges VAT-inclusive gross, matching what the first
     * invoice bills (a non-taxable / reverse-charge / addressless org yields net). An
     * invalid/expired code is a no-op (full price), deny-by-default.
     */
    private function amount(BillingSession $session, Plan $plan, string $currency): Money
    {
        return $this->grossOf($this->organization($session), $this->lines($session, $plan, $currency));
    }

    /**
     * Build the taxed lines for this checkout: the plan line at full net, plus a negated
     * discount line (taxed at the same rate) when a valid promo applies — mirroring the
     * invoice's discount-as-line shape so the checkout gross equals the invoice gross exactly.
     *
     * @return list<LineInput>
     */
    private function lines(BillingSession $session, Plan $plan, string $currency): array
    {
        // The SEAT/TIER-aware figure the first invoice bills (a checkout subscribes one seat), NOT
        // the base `price_minor` — which is often 0 for a graduated/volume plan, so a tiered plan
        // must not check out "free" then get invoiced. amountFor(currency, 1) == the first-invoice
        // charge, keeping checkout gross == first-invoice gross (preview == charge).
        $full = $plan->amountFor($currency, 1);
        $lines = [new LineInput($plan->name, 1, $full)];

        $coupon = $this->coupon($session);

        if ($coupon instanceof Coupon) {
            $discount = $this->coupons->forCoupon($coupon, $full, Carbon::now()->toDateTimeImmutable());

            if ($discount !== null && $discount->amount->isPositive()) {
                $lines[] = new LineInput(sprintf('Discount — %s', $coupon->code), 1, $discount->amount->negated());
            }
        }

        return $lines;
    }

    /**
     * Run the given lines through the engine quote builder against the org's tax context and
     * return the gross. A tax-pending org (no resolvable address) yields gross == net — the
     * same amount the un-taxed path charged, so an addressless signup is unaffected.
     *
     * @param  list<LineInput>  $lines
     */
    private function grossOf(Organization $organization, array $lines): Money
    {
        return $this->quotes->build($lines, $this->taxContexts->forOrganization($organization))->totals->gross;
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
     * The session's stable settlement reference, minted once. Reusing it across retries keeps the
     * gateway idempotency key stable and lets the settled webhook find the session to activate.
     *
     * The tax-aware GROSS the intent is created for is stamped alongside (once), so the settled
     * webhook can verify the settlement amount + currency against what was actually charged before
     * activating — money integrity. The expectation is refreshed each time the reference is
     * (re)stamped so it always reflects the amount the current intent asks the gateway for.
     */
    private function stampReference(BillingSession $session, Money $amount): string
    {
        if (is_string($session->payment_reference) && $session->payment_reference !== '') {
            $reference = $session->payment_reference;

            $session->forceFill([
                'expected_amount_minor' => $amount->minor(),
                'expected_currency' => $amount->currency(),
            ])->save();

            return $reference;
        }

        $reference = 'chk_'.Str::random(24);
        $session->forceFill([
            'payment_reference' => $reference,
            'expected_amount_minor' => $amount->minor(),
            'expected_currency' => $amount->currency(),
        ])->save();

        return $reference;
    }
}
