<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

use App\Billing\Notifications\Branding\SellerBranding;

/**
 * A resolved paywall panel: the gated capability, the plan that unlocks it, its price, and the
 * checkout deep-link to buy it — all sourced from the {@see App\Billing\Enforcement\Upgrade\UpgradeGate}
 * (the required plan + hosted-checkout URL are the gate's output, not recomputed here). When no
 * reachable plan grants the capability, `available` is false and there is no offer to show (the
 * panel then states the honest "no upgrade path" outcome).
 */
readonly class RenderedPaywall
{
    public function __construct(
        public bool $available,
        public string $gatedLabel,
        public string $gatedKind,
        public ?string $requiredPlanKey,
        public ?string $requiredPlanName,
        public ?string $priceFormatted,
        public ?string $priceInterval,
        public ?string $checkoutUrl,
        public SellerBranding $branding,
    ) {}
}
