<?php

declare(strict_types=1);

namespace App\Billing\Storefront\ValueObjects;

use App\Billing\Notifications\Branding\SellerBranding;

/**
 * A resolved paywall panel: the gated capability, the plan that unlocks it, its price, and —
 * only when the caller already holds an authorized checkout session — the deep-link to it. The
 * required plan comes from the {@see App\Billing\Enforcement\Upgrade\UpgradeGate}'s NON-minting
 * resolver: the public paywall never mints a session for an arbitrary org, so `checkoutUrl` is
 * null unless an existing session was supplied (the panel then shows a generic upgrade CTA). When
 * no reachable plan grants the capability, `available` is false and there is no offer to show.
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
