<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Models\PlanPrice;
use App\Models\Subscription;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrCalculator;

/**
 * Normalizes a subscription's plan price to its monthly-equivalent recurring amount in
 * the account's billing currency — the figure the engine's {@see MrrCalculator}
 * sums into MRR. Annual plans are divided to a monthly figure; a currency the plan is
 * not priced in yields zero (deny-by-default) rather than a fabricated rate.
 *
 * The amount is seat-aware: it is what the plan's {@see PlanPrice} charges for the
 * subscription's current quantity, priced by the SAME engine calculator that bills it
 * ({@see PlanPrice::toPrice()} → {@see Price::amountFor()}).
 * A `flat` price is the fixed amount regardless of seats (the engine bills quantity 1);
 * a `per_unit` price is the unit amount times seats; the tiered models
 * (graduated / volume / package / stairstep) are priced from their tier set. So a
 * seat/quantity change on a seat-priced plan moves contributing MRR, while a flat plan
 * is unchanged.
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

        // Deny-by-default: a currency the plan is not priced in yields zero rather than a
        // fabricated rate — mirrors Plan::priceFor() without throwing on the read path.
        $price = $plan->prices->firstWhere('currency', $currency);

        if (! $price instanceof PlanPrice) {
            return Money::zero($currency);
        }

        // Seat-aware: the engine prices the subscription's current quantity under the plan's
        // pricing model. Seats below one price as one (a live subscription has at least a seat).
        $amount = $price->toPrice()->amountFor(max(1, $subscription->seats));

        return $plan->interval === 'year' ? $amount->proratedBy(1, 12) : $amount;
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
