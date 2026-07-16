<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Payments\Contracts\DetachesPaymentMethod;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The engine's {@see FakePaymentGateway} extended into a coherent per-account card vault
 * that also honours {@see DetachesPaymentMethod} — the one operation the engine gateway
 * contract lacks at v0.3.0. Intent creation is inherited unchanged (so setup/payment
 * intents still record their requests and return a client secret); the four stored-method
 * operations plus detach share a single vault so list → default → remove is observable
 * end-to-end in a feature test without live gateway keys.
 */
class VaultPaymentGateway extends FakePaymentGateway implements DetachesPaymentMethod
{
    /** @var array<string, list<PaymentMethod>> */
    private array $vault = [];

    public function __construct(PaymentIntentStatus $intentStatus = PaymentIntentStatus::Succeeded)
    {
        parent::__construct(PaymentResult::succeeded('gw_ref'), null, $intentStatus);
    }

    /**
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array
    {
        return $this->vault[$account] ?? [];
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        $isDefault = ($this->vault[$account] ?? []) === [];

        $method = new PaymentMethod(
            id: $paymentMethodId,
            brand: 'visa',
            last4: '4242',
            expMonth: 12,
            expYear: 2030,
            isDefault: $isDefault,
        );

        $this->vault[$account][] = $method;

        return $method;
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->vault[$account] = array_map(
            static fn (PaymentMethod $method): PaymentMethod => new PaymentMethod(
                id: $method->id,
                brand: $method->brand,
                last4: $method->last4,
                expMonth: $method->expMonth,
                expYear: $method->expYear,
                isDefault: $method->id === $paymentMethodId,
            ),
            $this->vault[$account] ?? [],
        );
    }

    public function detach(string $account, string $paymentMethodId): void
    {
        $this->vault[$account] = array_values(array_filter(
            $this->vault[$account] ?? [],
            static fn (PaymentMethod $method): bool => $method->id !== $paymentMethodId,
        ));
    }
}
