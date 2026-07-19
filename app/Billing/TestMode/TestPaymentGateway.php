<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use App\Billing\TestMode\Contracts\ResolvesTestChargeOutcome;
use App\Billing\TestMode\Enums\TestChargeOutcome;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;

/**
 * The sandbox gateway. Unlike the real Stripe/manual gateways it NEVER reaches an external
 * network: a test charge settles or declines synchronously and deterministically, resolved
 * from the bound test clock's `charge_outcome` (see {@see ResolvesTestChargeOutcome}). That
 * makes the whole money path drivable from a test clock — a `succeed` clock renews clean, a
 * `decline` clock opens the dunning schedule — with no real money and no vaulted card.
 *
 * Everything else (customer minting, saved methods, intents, refunds) delegates to the
 * dependency-free {@see ManualPaymentGateway}: a test account has no card vault either, so its
 * honest vault-less shape is exactly right. A settled charge returns a `test_…` reference so a
 * test payment is unmistakable in any reconciliation view.
 */
readonly class TestPaymentGateway implements PaymentGateway
{
    public function __construct(
        private ResolvesTestChargeOutcome $outcomes,
        private ManualPaymentGateway $manual = new ManualPaymentGateway,
    ) {}

    public function name(): string
    {
        return 'test';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        return $this->outcomes->outcome($intent) === TestChargeOutcome::Decline
            ? PaymentResult::failed('test-mode declined charge')
            : PaymentResult::succeeded('test_ch_'.$intent->reference);
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        return PaymentResult::succeeded('test_re_'.$intent->id);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        return $this->manual->createPaymentIntent($request);
    }

    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        return $this->manual->createSetupIntent($request);
    }

    public function createCustomer(string $account, ?string $email = null, ?string $name = null): string
    {
        return 'test:'.$account;
    }

    /** @return list<PaymentMethod> */
    public function paymentMethods(string $account): array
    {
        return $this->manual->paymentMethods($account);
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        return $this->manual->attachPaymentMethod($account, $paymentMethodId);
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->manual->setDefaultPaymentMethod($account, $paymentMethodId);
    }

    public function detachPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->manual->detachPaymentMethod($account, $paymentMethodId);
    }
}
