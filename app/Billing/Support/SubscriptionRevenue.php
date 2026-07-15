<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Models\Subscription;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrCalculator;

/**
 * Normalizes a subscription's plan price to its monthly-equivalent recurring amount in
 * the account's billing currency — the figure the engine's {@see MrrCalculator}
 * sums into MRR. Annual plans are divided to a monthly figure; a currency the plan is
 * not priced in yields zero (deny-by-default) rather than a fabricated rate.
 */
class SubscriptionRevenue
{
    public static function monthly(Subscription $subscription): Money
    {
        $currency = self::currency($subscription);
        $plan = $subscription->plan;

        if ($plan === null) {
            return Money::zero($currency);
        }

        try {
            $price = $plan->priceFor($currency);
        } catch (\Throwable) {
            return Money::zero($currency);
        }

        return $plan->interval === 'year' ? $price->proratedBy(1, 12) : $price;
    }

    public static function currency(Subscription $subscription): string
    {
        $currency = $subscription->organization?->billing_currency;

        if (is_string($currency) && $currency !== '') {
            return $currency;
        }

        $default = config('billing.default_currency');

        return is_string($default) ? $default : 'DKK';
    }
}
