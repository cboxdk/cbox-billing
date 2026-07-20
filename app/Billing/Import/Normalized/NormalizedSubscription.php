<?php

declare(strict_types=1);

namespace App\Billing\Import\Normalized;

use Carbon\CarbonImmutable;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * A provider subscription mapped into the app's shape: the provider id, the customer + plan it
 * binds (by their provider ids — resolved to app records through the run's mappings), the
 * normalized status, the seat/quantity, the currency, and — critically for migration fidelity —
 * the historical period anchors, trial end, cancellation time and creation time, all preserved
 * so MRR movements and cohorts line up after the cut-over.
 *
 * `status` is one of the app's {@see SubscriptionStatus} values
 * (`active` / `trialing` / `past_due` / `canceled` / `paused`); the adapter has already mapped
 * the provider's own status vocabulary onto it. `couponCode`, when present, binds an imported
 * coupon to the subscription.
 */
readonly class NormalizedSubscription
{
    public function __construct(
        public string $sourceId,
        public string $customerSourceId,
        public string $planSourceId,
        public string $status,
        public int $seats,
        public ?string $currency,
        public ?CarbonImmutable $currentPeriodStart,
        public ?CarbonImmutable $currentPeriodEnd,
        public ?CarbonImmutable $trialEndsAt = null,
        public ?CarbonImmutable $canceledAt = null,
        public ?CarbonImmutable $createdAt = null,
        public ?string $couponCode = null,
    ) {}
}
