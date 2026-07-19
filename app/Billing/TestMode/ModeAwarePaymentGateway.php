<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;

/**
 * The gateway the app resolves everywhere. It routes each call to the real gateway
 * (Stripe/manual — whatever was configured) in LIVE mode, and to the {@see TestPaymentGateway}
 * in TEST mode. This is the hard guarantee that a test-mode charge can NEVER touch a live
 * Stripe account: the decision is made here, once, from the ambient {@see BillingContext}, so
 * no test flow can accidentally reach the real gateway.
 */
readonly class ModeAwarePaymentGateway implements PaymentGateway
{
    public function __construct(
        private BillingContext $context,
        private PaymentGateway $live,
        private TestPaymentGateway $test,
    ) {}

    private function gateway(): PaymentGateway
    {
        return $this->context->isTest() ? $this->test : $this->live;
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
