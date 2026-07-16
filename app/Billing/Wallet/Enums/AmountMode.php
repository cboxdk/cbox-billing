<?php

declare(strict_types=1);

namespace App\Billing\Wallet\Enums;

use Cbox\Billing\Wallet\Contracts\GrantAmount;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\ValueObjects\Distributed;
use Cbox\Billing\Wallet\ValueObjects\Fixed;

/**
 * How a plan grant's authored `amount` is sized within a billing period (ADR-0014) — the
 * catalog seam onto the engine's two {@see GrantAmount} shapes:
 *
 *  - `Fixed` grants the whole `amount` at EACH cadence boundary in the period — a
 *    `Fixed(1_000, Monthly)` grants 1,000 every month, regardless of how many months the
 *    period spans.
 *  - `Distributed` treats `amount` as a period TOTAL and splits it evenly across the
 *    cadence slices the period holds, remainder-safe — a yearly `Distributed(1_200_000,
 *    Monthly)` drips 12 × 100,000, the slices summing to exactly the total.
 *
 * Keeping the choice in the catalog (rather than hard-coding {@see Fixed}) lets a plan
 * author a "spread this annual allotment monthly" grant that the recurring renewal drips
 * on the finer cadence.
 */
enum AmountMode: string
{
    case Fixed = 'fixed';
    case Distributed = 'distributed';

    /**
     * Project the catalog `(amount, cadence)` into the engine {@see GrantAmount} this mode
     * names — a {@see Fixed} per-boundary amount or a {@see Distributed} period total.
     */
    public function amount(int $amount, GrantCadence $cadence): GrantAmount
    {
        return match ($this) {
            self::Fixed => new Fixed($amount, $cadence),
            self::Distributed => new Distributed($amount, $cadence),
        };
    }
}
