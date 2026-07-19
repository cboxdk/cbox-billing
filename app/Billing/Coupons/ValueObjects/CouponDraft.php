<?php

declare(strict_types=1);

namespace App\Billing\Coupons\ValueObjects;

use App\Billing\Coupons\CouponAuthoring;
use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use Illuminate\Support\Carbon;

/**
 * The validated shape a coupon is created / updated from — the authoring service's input.
 * A plain data carrier: the write service ({@see CouponAuthoring})
 * validates and persists it. Amounts are minor units; the currency is required only for a
 * fixed-amount discount.
 */
readonly class CouponDraft
{
    /**
     * @param  list<string>  $planKeys
     */
    public function __construct(
        public string $code,
        public ?string $name,
        public CouponDiscountKind $kind,
        public ?int $percentOff,
        public ?int $amountOffMinor,
        public ?string $currency,
        public CouponDuration $duration,
        public ?int $durationInPeriods,
        public ?int $maxRedemptions,
        public ?int $maxRedemptionsPerCustomer,
        public ?Carbon $redeemBy,
        public CouponScope $scope,
        public array $planKeys,
        public bool $active,
    ) {}
}
