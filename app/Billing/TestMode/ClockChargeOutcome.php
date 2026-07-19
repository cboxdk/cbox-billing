<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use App\Billing\TestMode\Contracts\ResolvesTestChargeOutcome;
use App\Billing\TestMode\Enums\TestChargeOutcome;
use App\Models\Invoice;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;

/**
 * Resolves a test charge's outcome from the invoice's subscription's bound test clock. A
 * charge references the invoice by its legal number; we follow number → subscription → clock
 * and return that clock's `charge_outcome`. Anything without a bound clock (or not found)
 * settles — the safe default, so a plain test charge just works while the dunning path is
 * opt-in per clock.
 */
readonly class ClockChargeOutcome implements ResolvesTestChargeOutcome
{
    public function outcome(PaymentIntent $intent): TestChargeOutcome
    {
        $invoice = Invoice::query()
            ->where('number', $intent->reference)
            ->with('subscription.testClock')
            ->first();

        $clock = $invoice?->subscription?->testClock;

        return $clock?->chargeOutcome() ?? TestChargeOutcome::Succeed;
    }
}
