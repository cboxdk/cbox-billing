<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use App\Billing\Mode\BillingContext;
use App\Billing\TestMode\TestPaymentGateway;
use App\Models\EnvironmentGateway;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;
use Closure;

/**
 * The gateway the app resolves everywhere, generalising the old test/live {@see ModeAwarePaymentGateway}
 * onto first-class ENVIRONMENTS. It routes each call to the gateway the CURRENT plane charges
 * through, decided once per call from the ambient {@see BillingContext}:
 *
 *   1. The plane has its OWN active DB credentials → build its real gateway from them (per-plane
 *      Stripe account). This is what gives sandbox/live account separation.
 *   2. Otherwise PRODUCTION → the legacy global env-var gateway (`$globalLive` — Stripe when
 *      `STRIPE_SECRET` is set, else the manual gateway). BC: existing single-plane deployments,
 *      which have nothing in `environment_gateways`, keep charging exactly as before.
 *   3. Otherwise (a sandbox with no keys) → the fake {@see TestPaymentGateway}, which can never
 *      reach a real account. This is the hard guarantee a keyless sandbox charge stays fake.
 *
 * The delegate is memoised per plane key within the request so repeated calls don't rebuild the
 * Stripe client. Because the credentials are validated to be TEST keys for a sandbox and LIVE keys
 * for production on save, case 1 can never charge a real card from a sandbox.
 */
readonly class EnvironmentAwarePaymentGateway implements PaymentGateway
{
    /**
     * @param  Closure(EnvironmentGateway): PaymentGateway  $stripeFactory  builds a real gateway from a plane's credentials
     */
    public function __construct(
        private BillingContext $context,
        private EnvironmentGatewayStore $store,
        private PaymentGateway $globalLive,
        private TestPaymentGateway $test,
        private Closure $stripeFactory,
        private GatewayDelegateMemo $memo,
    ) {}

    private function gateway(): PaymentGateway
    {
        $environment = $this->context->environment();
        $key = $environment->key;

        $cached = $this->memo->get($key);

        if ($cached instanceof PaymentGateway) {
            return $cached;
        }

        $credentials = $this->store->activeFor($key);

        $gateway = $credentials !== null
            ? ($this->stripeFactory)($credentials)
            : ($environment->isProduction() ? $this->globalLive : $this->test);

        $this->memo->put($key, $gateway);

        return $gateway;
    }

    public function name(): string
    {
        return $this->gateway()->name();
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        return $this->gateway()->charge($intent);
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        return $this->gateway()->refund($intent);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        return $this->gateway()->createPaymentIntent($request);
    }

    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        return $this->gateway()->createSetupIntent($request);
    }

    public function createCustomer(string $account, ?string $email = null, ?string $name = null): string
    {
        return $this->gateway()->createCustomer($account, $email, $name);
    }

    /** @return list<PaymentMethod> */
    public function paymentMethods(string $account): array
    {
        return $this->gateway()->paymentMethods($account);
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        return $this->gateway()->attachPaymentMethod($account, $paymentMethodId);
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->gateway()->setDefaultPaymentMethod($account, $paymentMethodId);
    }

    public function detachPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->gateway()->detachPaymentMethod($account, $paymentMethodId);
    }
}
