<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Enforcement\Upgrade\UpgradeGate;
use App\Billing\Notifications\Branding\BrandingResolver;
use App\Billing\Storefront\ValueObjects\RenderedPaywall;
use App\Billing\Support\MoneyFormatter;
use App\Models\Feature;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;

/**
 * Resolves a drop-in paywall panel (#57): when a feature or metered-limit gate blocks, this turns
 * the block into the branded "upgrade to unlock" offer. It REUSES the {@see UpgradeGate} — the
 * required plan and the hosted-checkout deep-link are the gate's output, never recomputed here —
 * and only enriches it for display: the human label of the gated capability, and the required
 * plan's price in the org's billing currency (formatted through the same {@see MoneyFormatter} as
 * everywhere else).
 *
 * Deny-by-default: when the gate finds no reachable plan (the org already has the capability, or
 * nothing grants it), the paywall is `available: false` and carries no fabricated offer.
 */
readonly class PaywallPresenter
{
    public function __construct(
        private UpgradeGate $gate,
        private BrandingResolver $branding,
        private ResolvesAccountCurrency $currencies,
    ) {}

    /** The paywall for a blocked boolean/config feature the org lacks. */
    public function forFeature(string $org, string $featureKey): RenderedPaywall
    {
        $feature = Feature::query()->where('key', $featureKey)->first();
        $label = $feature instanceof Feature ? $feature->name : $featureKey;

        return $this->build($org, $label, 'feature', $this->gate->forFeature($org, $featureKey));
    }

    /** The paywall for a blocked metered limit (a disabled meter or an exhausted allowance). */
    public function forMeter(string $org, string $meterKey): RenderedPaywall
    {
        $meter = Meter::query()->where('key', $meterKey)->first();
        $label = $meter instanceof Meter ? $meter->name : $meterKey;

        return $this->build($org, $label, 'usage', $this->gate->forMeter($org, $meterKey));
    }

    /**
     * @param  array{required_plan: string, checkout_url: string|null}|null  $offer
     */
    private function build(string $org, string $gatedLabel, string $gatedKind, ?array $offer): RenderedPaywall
    {
        $branding = $this->branding->forSeller(null);

        if ($offer === null) {
            return new RenderedPaywall(
                available: false,
                gatedLabel: $gatedLabel,
                gatedKind: $gatedKind,
                requiredPlanKey: null,
                requiredPlanName: null,
                priceFormatted: null,
                priceInterval: null,
                checkoutUrl: null,
                branding: $branding,
            );
        }

        $plan = Plan::query()->with('prices')->where('key', $offer['required_plan'])->first();
        [$priceFormatted, $priceInterval] = $this->price($org, $plan);

        return new RenderedPaywall(
            available: true,
            gatedLabel: $gatedLabel,
            gatedKind: $gatedKind,
            requiredPlanKey: $offer['required_plan'],
            requiredPlanName: $plan instanceof Plan ? $plan->name : ucfirst($offer['required_plan']),
            priceFormatted: $priceFormatted,
            priceInterval: $priceInterval,
            checkoutUrl: $offer['checkout_url'],
            branding: $branding,
        );
    }

    /**
     * The required plan's price in the org's billing currency, formatted for display. Falls back to
     * the plan's first-priced currency when it is not priced in the org's currency, and to no price
     * at all when the plan carries none — never a fabricated amount.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function price(string $org, ?Plan $plan): array
    {
        if (! $plan instanceof Plan) {
            return [null, null];
        }

        $currency = $this->orgCurrency($org);
        $price = $plan->prices->firstWhere('currency', $currency);

        if (! $price instanceof PlanPrice) {
            $price = $plan->prices->first();
        }

        if (! $price instanceof PlanPrice) {
            return [null, null];
        }

        $per = $plan->billingInterval()->value === 'yearly' ? '/yr' : '/mo';

        return [MoneyFormatter::minor($price->price_minor, $price->currency), $per];
    }

    private function orgCurrency(string $org): string
    {
        $organization = Organization::query()->find($org);

        return $organization instanceof Organization
            ? $this->currencies->for($organization)
            : $this->currencies->default();
    }
}
