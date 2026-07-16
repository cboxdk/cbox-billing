<?php

declare(strict_types=1);

namespace Tests\Support;

use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The engine's {@see FakePaymentGateway} — itself a coherent per-account card vault that at
 * v0.4.0 also honours `createCustomer` and `detachPaymentMethod` — with one test-only twist:
 * `createCustomer` mints a UNIQUE id per call and counts the calls. Uniqueness means a reused
 * id can only be explained by the resolver having stored it (a deterministic id would reuse
 * regardless), and the counter proves the customer was minted exactly once across many
 * intents for one org. Intent creation and the stored-method + detach operations are inherited
 * unchanged, so list → default → remove is observable end-to-end without live gateway keys.
 */
class VaultPaymentGateway extends FakePaymentGateway
{
    public int $customerCalls = 0;

    public function __construct(PaymentIntentStatus $intentStatus = PaymentIntentStatus::Succeeded)
    {
        parent::__construct(PaymentResult::succeeded('gw_ref'), null, $intentStatus);
    }

    public function createCustomer(string $account, ?string $email = null, ?string $name = null): string
    {
        $this->customerCalls++;

        return sprintf('cus_test_%s_%d', $account, $this->customerCalls);
    }
}
