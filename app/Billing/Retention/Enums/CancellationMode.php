<?php

declare(strict_types=1);

namespace App\Billing\Retention\Enums;

/**
 * How a customer's cancellation is enacted — the retention fork:
 *
 *  - `Immediate` — end the subscription now (the engine's cancel-to-null forfeiture fires).
 *  - `PeriodEnd` — schedule the cancellation for the current period end; the subscription
 *    keeps serving until it renews into the cancel (the softer default).
 *  - `Pause`     — pause instead of cancel; access + metering suspend, nothing is charged,
 *    and the customer can resume later (a retention save, not a churn).
 */
enum CancellationMode: string
{
    case Immediate = 'immediate';
    case PeriodEnd = 'period_end';
    case Pause = 'pause';
}
