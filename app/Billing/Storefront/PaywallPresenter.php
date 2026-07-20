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
use Cbox\Billing\Subscription\Enums\BillingInterval;

/**
 * Resolves a drop-in paywall panel (#57): when a feature or metered-limit gate blocks, this turns
 * the block into the branded "upgrade to unlock" offer. It REUSES the {@see UpgradeGate} for the
 * required plan — but through the NON-minting resolver, because the paywall is public and
 * unauthenticated: it must NOT create a real `BillingSession` for an arbitrary org (that would
 * disclose org existence cross-tenant and spawn unbounded rows). The checkout deep-link is passed
 * in only when the caller already holds an authorized session; otherwise the panel shows a generic
 * upgrade CTA. The presenter enriches the offer for display: the human label of the gated
 * capability and the required plan's price in the org's billing currency (formatted through the
 * same {@see MoneyFormatter} as everywhere else).
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

    /**
     * The paywall for a blocked boolean/config feature the org lacks. `$checkoutUrl` is the deep-link
     * to an EXISTING authorized checkout session when the caller supplied one — never minted here.
     */
    public function forFeature(string $org, string $featureKey, ?string $checkoutUrl = null): RenderedPaywall
    {
        $feature = Feature::query()->where('key', $featureKey)->first();
        $label = $feature instanceof Feature ? $feature->name : $featureKey;

        return $this->build($org, $label, 'feature', $this->gate->requiredPlanForFeature($org, $featureKey), $checkoutUrl);
    }

    /**
     * The paywall for a blocked metered limit (a disabled meter or an exhausted allowance).
     * `$checkoutUrl` is an existing authorized checkout session's deep-link, when provided — never minted.
     */
    public function forMeter(string $org, string $meterKey, ?string $checkoutUrl = null): RenderedPaywall
    {
        $meter = Meter::query()->where('key', $meterKey)->first();
        $label = $meter instanceof Meter ? $meter->name : $meterKey;

        return $this->build($org, $label, 'usage', $this->gate->requiredPlanForMeter($org, $meterKey), $checkoutUrl);
    }

    private function build(string $org, string $gatedLabel, string $gatedKind, ?string $requiredPlan, ?string $checkoutUrl): RenderedPaywall
    {
        $branding = $this->branding->forSeller(null);

        if ($requiredPlan === null) {
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

        $plan = Plan::query()->with('prices')->where('key', $requiredPlan)->first();
        [$priceFormatted, $priceInterval] = $this->price($org, $plan);

        return new RenderedPaywall(
            available: true,
            gatedLabel: $gatedLabel,
            gatedKind: $gatedKind,
            requiredPlanKey: $requiredPlan,
            requiredPlanName: $plan instanceof Plan ? $plan->name : ucfirst($requiredPlan),
            priceFormatted: $priceFormatted,
            priceInterval: $priceInterval,
            checkoutUrl: $checkoutUrl,
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

        $per = $plan->billingInterval() === BillingInterval::Yearly ? '/yr' : '/mo';

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
