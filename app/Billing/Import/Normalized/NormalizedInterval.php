<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Cbox\Billing\Subscription\Enums\BillingInterval;

/**
 * The billing cadence a normalized plan renews on, constrained to the two the engine bills —
 * {@see BillingInterval} carries only monthly and yearly. A
 * provider cadence that is neither (a Stripe `week`/`day` price, a Recurly multi-month term) is
 * NOT coerced: the adapter records the raw provider string and leaves the normalized interval
 * null, so the importer flags it as an unsupported-interval conflict rather than silently
 * billing it monthly.
 */
enum NormalizedInterval: string
{
    case Monthly = 'month';
    case Yearly = 'year';

    /**
     * Map a provider interval token (already lower-cased) to the engine cadence, or null when it
     * is not a supported cadence. Handles the common singular/plural spellings across providers
     * (`month`/`months`, `year`/`yearly`/`annual`).
     */
    public static function fromProvider(?string $token): ?self
    {
        return match (strtolower(trim((string) $token))) {
            'month', 'months', 'monthly', 'mo' => self::Monthly,
            'year', 'years', 'yearly', 'annual', 'annually' => self::Yearly,
            default => null,
        };
    }
}
