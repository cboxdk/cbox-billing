<?php

declare(strict_types=1);

namespace App\Billing\TestMode\Contracts;

use App\Billing\TestMode\Enums\TestChargeOutcome;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;

/**
 * Decides — deterministically — whether a fake-gateway test charge settles or declines. The
 * default resolution keys off the charged invoice's subscription's bound test clock
 * (`test_clocks.charge_outcome`), so an integrator flips a whole scenario between the happy
 * path and the dunning path by setting one field on the clock.
 */
interface ResolvesTestChargeOutcome
{
    public function outcome(PaymentIntent $intent): TestChargeOutcome;
}
