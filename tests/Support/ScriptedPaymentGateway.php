<?php

declare(strict_types=1);

namespace Tests\Support;

use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * A gateway whose {@see charge()} pops a scripted sequence of {@see PaymentResult}s, so a
 * test can drive a real decline-then-recover flow: e.g. a failed renewal charge followed by
 * a settled retry. Once the script is exhausted the last result repeats, so a test only has
 * to script the transitions it cares about. Every other operation is inherited from
 * {@see FakePaymentGateway} unchanged.
 */
class ScriptedPaymentGateway extends FakePaymentGateway
{
    /** @var list<PaymentResult> */
    public array $chargeScript;

    /** @param  list<PaymentResult>  $script */
    public function __construct(array $script)
    {
        parent::__construct(PaymentResult::failed('scripted'));

        $this->chargeScript = $script;
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        // HONOUR THE GUARD FIRST. This extends FakePaymentGateway, whose whole point is that a
        // fake must refuse everything production refuses — and overriding charge() dropped it.
        // The dunning suites then asserted against a `pending` result that the real Stripe and
        // Mollie gateways cannot produce: they return FAILED for an intent naming no vaulted
        // instrument, which opens dunning. Scripting a response for a charge that could never
        // have been attempted tests nothing.
        $refused = parent::charge($intent);

        if ($refused->status === PaymentStatus::Failed && ! $intent->isOffSession()) {
            return $refused;
        }

        if (count($this->chargeScript) > 1) {
            return array_shift($this->chargeScript);
        }

        return $this->chargeScript[0] ?? PaymentResult::failed('scripted-empty');
    }
}
