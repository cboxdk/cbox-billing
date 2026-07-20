<?php

declare(strict_types=1);

namespace App\Billing\Enforcement\Upgrade;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Metering\EntitlementsView;
use App\Billing\Metering\UsageSummaryView;
use App\Models\Organization;
use App\Models\Plan;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;

/**
 * Turns an enforcement refusal into an upgrade offer (ADR-0009/#52): the `{required_plan,
 * checkout_url}` a denial or disabled meter carries so the caller can present the path to
 * unlock rather than a bare refusal. Composes {@see ResolvesRequiredPlan} (which plan) with
 * the hosted checkout session service (the pre-built deep-link to buy it, reusing an open
 * session for the same plan so repeated denials do not spawn rows).
 *
 * Deny-by-default: no reachable plan grants the meter → no offer (null), never a fabricated
 * or unreachable target.
 */
readonly class UpgradeGate
{
    public function __construct(
        private ResolvesRequiredPlan $requiredPlans,
        private ResolvesRequiredFeaturePlan $requiredFeaturePlans,
        private ManagesBillingSessions $sessions,
        private EntitlementsView $entitlements,
        private UsageSummaryView $usage,
        private string $returnUrl,
    ) {}

    /**
     * The upgrade offer for a single meter/feature, or null when the org already has the
     * best entitlement or no reachable plan grants it.
     *
     * @return array{required_plan: string, checkout_url: string|null}|null
     */
    public function forMeter(string $org, string $meter): ?array
    {
        $plan = $this->requiredPlans->resolve($org, $meter);

        if (! $plan instanceof Plan) {
            return null;
        }

        return [
            'required_plan' => $plan->key,
            'checkout_url' => $this->checkoutUrl($org, $plan),
        ];
    }

    /**
     * The upgrade offer for a single BOOLEAN/CONFIG feature the org lacks, or null when the org
     * already has the feature or no reachable plan grants it. This is the feature sibling of
     * {@see forMeter()}: the enforce→upgrade CTA a capability gates on when a feature is missing.
     *
     * @return array{required_plan: string, checkout_url: string|null}|null
     */
    public function forFeature(string $org, string $feature): ?array
    {
        $plan = $this->requiredFeaturePlans->resolve($org, $feature);

        if (! $plan instanceof Plan) {
            return null;
        }

        return [
            'required_plan' => $plan->key,
            'checkout_url' => $this->checkoutUrl($org, $plan),
        ];
    }

    /**
     * Attach an `upgrade` key to every not-granted feature in a feature-set payload that has a
     * reachable upgrade path — the enforce→upgrade bridge on the `/entitlements/{org}/features`
     * response. A granted feature, or one with no reachable path, is left untouched.
     *
     * @param  array<string, array{type: string|null, enabled: bool, value: int|string|null, source: string}>  $features
     * @return array<string, array<string, mixed>>
     */
    public function enrichFeatures(string $org, array $features): array
    {
        $enriched = [];

        foreach ($features as $key => $feature) {
            if ($feature['enabled'] === false) {
                $offer = $this->forFeature($org, $key);

                if ($offer !== null) {
                    $feature['upgrade'] = $offer;
                }
            }

            $enriched[$key] = $feature;
        }

        return $enriched;
    }

    /**
     * The upgrade offer for the meter that actually blocked a multi-bucket reservation, or
     * null when none is identifiable / upgradable. The engine's outcome does not name the
     * offending bucket, so the blocking meter is reconstructed from the org's resolved
     * entitlements and reconciled usage.
     *
     * @param  list<BucketRequest>  $requests
     * @return array{required_plan: string, checkout_url: string|null}|null
     */
    public function forReservation(string $org, array $requests): ?array
    {
        $meter = $this->blockingMeter($org, $requests);

        return $meter === null ? null : $this->forMeter($org, $meter);
    }

    /**
     * Attach an `upgrade` key to every disabled meter in an entitlements payload that has a
     * reachable upgrade path — the enforce→upgrade bridge on the `/entitlements/{org}`
     * response. Meters that are enabled, or disabled with no path, are left untouched.
     *
     * @param  array<string, array{enabled: bool, allowance: int|null, weight: float|null, overage: string}>  $meters
     * @return array<string, array<string, mixed>>
     */
    public function enrichEntitlements(string $org, array $meters): array
    {
        $enriched = [];

        foreach ($meters as $key => $meter) {
            if ($meter['enabled'] === false) {
                $offer = $this->forMeter($org, $key);

                if ($offer !== null) {
                    $meter['upgrade'] = $offer;
                }
            }

            $enriched[$key] = $meter;
        }

        return $enriched;
    }

    /**
     * The first requested meter whose bucket would be refused for `$org`: an unknown or
     * disabled meter, or a finite allowance the request would push past. Null when every
     * requested bucket fits (so the denial was not an allowance/entitlement fact).
     *
     * @param  list<BucketRequest>  $requests
     */
    private function blockingMeter(string $org, array $requests): ?string
    {
        $policies = $this->entitlements->forOrganization($org);
        $summary = $this->usage->forOrganization($org)['meters'];

        foreach ($requests as $request) {
            $policy = $policies[$request->meter] ?? null;

            // Unknown (deny-by-default) or disabled → blocked.
            if ($policy === null || $policy['enabled'] !== true) {
                return $request->meter;
            }

            $allowance = $policy['allowance'];

            // Unlimited never blocks.
            if ($allowance === null) {
                continue;
            }

            $used = $summary[$request->meter]['used'] ?? 0;

            if ($used + $request->estimate > $allowance) {
                return $request->meter;
            }
        }

        return null;
    }

    /** The pre-built hosted-checkout deep-link to buy `$plan`, or null when the org is unknown. */
    private function checkoutUrl(string $org, Plan $plan): ?string
    {
        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return null;
        }

        $session = $this->sessions->openOrReuseCheckout($organization, $plan, null, $this->returnUrl);

        return route('hosted.checkout.show', $session->token);
    }
}
