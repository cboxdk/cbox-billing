<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;

/**
 * A provider coupon mapped into the app's shape. `kind` is `percent` or `fixed`; a percent
 * coupon carries {@see $percentOff} (1–100), a fixed coupon {@see $amountOffMinor} (minor units)
 * + {@see $currency}. `duration` is `once` / `repeating` / `forever` (the app's coupon duration
 * vocabulary), with {@see $durationInPeriods} required only for `repeating`.
 *
 * The adapter has already translated the provider's own discount/duration vocabulary
 * (Stripe `percent_off`/`amount_off`/`duration`, Chargebee `discount_percentage`/
 * `discount_amount`, Recurly `discount_percent`/`discount_in_cents`) into these fields.
 */
readonly class NormalizedCoupon
{
    public function __construct(
        public string $sourceId,
        public string $code,
        public ?string $name,
        public string $kind,
        public ?int $percentOff,
        public ?int $amountOffMinor,
        public ?string $currency,
        public string $duration,
        public ?int $durationInPeriods,
        public ?int $maxRedemptions,
        public ?CarbonImmutable $redeemBy = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
