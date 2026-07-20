<?php

declare(strict_types=1);

namespace App\Billing\Enforcement\Upgrade;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Features\FeatureEntitlements;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Illuminate\Support\Collection;

/**
 * The enforce→upgrade bridge for a BOOLEAN/CONFIG feature (the sibling of {@see
 * ResolvesRequiredPlan}, which does the same for a metered bucket): given an org and a feature it
 * lacks, find the **minimum offered plan** whose feature grants unlock that feature and that the
 * org may actually reach.
 *
 * Deny-by-default throughout, exactly as the metered resolver:
 *
 *  - The org must not already have the feature (resolved via {@see FeatureEntitlements}); an org
 *    that already has it — by plan or by an org-level override — has no upgrade (`resolve()`
 *    returns null).
 *  - Only `active` (offered) plans are candidates — a legacy plan is never a target.
 *  - A candidate must carry an enabled `plan_features` grant for the feature.
 *  - A candidate is reachable only when the engine's {@see TransitionPolicy} allows the move from
 *    the org's current plan; with no current subscription every offered plan is a fresh-subscribe
 *    candidate.
 *  - The winner is the cheapest such plan in the account's currency; a plan not priced in that
 *    currency is skipped rather than offered at a fabricated rate.
 */
readonly class ResolvesRequiredFeaturePlan
{
    public function __construct(
        private FeatureEntitlements $features,
        private TransitionPolicy $transitions,
        private ResolvesAccountCurrency $currencies,
    ) {}

    /** The minimum offered, reachable plan that grants `$feature` for `$org`, or null. */
    public function resolve(string $org, string $feature): ?Plan
    {
        // Already granted (by plan or override): nothing to upgrade to for this feature.
        if ($this->features->has($org, $feature)) {
            return null;
        }

        $currency = $this->currency($org);
        $currentPlan = $this->currentPlan($org);
        $fromProduct = $currentPlan?->toCatalogProduct();

        $best = null;
        $bestMinor = null;

        foreach ($this->offeredPlans() as $candidate) {
            if ($currentPlan !== null && $candidate->key === $currentPlan->key) {
                continue;
            }

            if (! $candidate->prices->contains('currency', $currency)) {
                continue;
            }

            if (! $this->grantsFeature($candidate, $feature)) {
                continue;
            }

            if ($fromProduct !== null
                && ! $this->transitions->canTransition($fromProduct, $candidate->toCatalogProduct())->isAllowed()) {
                continue;
            }

            $minor = $candidate->priceFor($currency)->minor();

            if ($bestMinor === null || $minor < $bestMinor) {
                $best = $candidate;
                $bestMinor = $minor;
            }
        }

        return $best;
    }

    /** Whether `$candidate` carries an enabled feature grant for `$feature`. */
    private function grantsFeature(Plan $candidate, string $feature): bool
    {
        foreach ($candidate->features as $grant) {
            if ($grant->enabled && $grant->feature?->key === $feature) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Plan> */
    private function offeredPlans(): Collection
    {
        return Plan::query()
            ->with(['prices', 'features.feature', 'product'])
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    private function currentPlan(string $org): ?Plan
    {
        $subscription = Subscription::query()
            ->with(['plan.prices', 'plan.product'])
            ->where('organization_id', $org)
            ->where('status', 'active')
            ->latest('current_period_start')
            ->first();

        return $subscription instanceof Subscription ? $subscription->plan : null;
    }

    /** The account's billing currency, falling back to the app default when unknown. */
    private function currency(string $org): string
    {
        $organization = Organization::query()->find($org);

        return $organization instanceof Organization
            ? $this->currencies->for($organization)
            : $this->currencies->default();
    }
}
