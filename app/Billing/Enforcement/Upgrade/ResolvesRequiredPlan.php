<?php

declare(strict_types=1);

namespace App\Billing\Enforcement\Upgrade;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Metering\EntitlementsView;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Illuminate\Support\Collection;

/**
 * The enforce→upgrade bridge (ADR-0009/#52): given an org and the meter/feature its
 * request was refused on, find the **minimum offered plan** whose catalog entitlements
 * grant (or improve) that meter and that the org may actually reach.
 *
 * Deny-by-default throughout:
 *
 *  - Only `active` (offered) plans are candidates — a legacy plan is never a target.
 *  - A candidate is reachable only when the engine's {@see TransitionPolicy} allows the
 *    move from the org's current plan (respecting plan families + declared cross-family
 *    edges); a legacy/blocked target is refused by the policy itself. With no current
 *    subscription there is no transition to gate — every offered plan is a fresh-subscribe
 *    candidate.
 *  - "Grants the meter" means the candidate's entitlement is enabled AND strictly better
 *    than what the org has today: it enables an absent/disabled meter, lifts a finite
 *    allowance, or makes it unlimited. An org already on the best entitlement (enabled +
 *    unlimited) has no upgrade — `resolve()` returns null.
 *  - The winner is the cheapest such plan in the account's currency; a plan not priced in
 *    that currency is skipped rather than offered at a fabricated rate.
 */
readonly class ResolvesRequiredPlan
{
    public function __construct(
        private EntitlementsView $entitlements,
        private TransitionPolicy $transitions,
        private ResolvesAccountCurrency $currencies,
    ) {}

    /** The minimum offered, reachable plan that improves `$meter` for `$org`, or null. */
    public function resolve(string $org, string $meter): ?Plan
    {
        $currency = $this->currency($org);
        $current = $this->entitlements->forOrganization($org)[$meter] ?? null;

        // Already on the strongest possible entitlement (enabled + unlimited): nothing to
        // upgrade to for this meter.
        if ($current !== null && $current['enabled'] === true && $current['allowance'] === null) {
            return null;
        }

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

            if (! $this->improvesMeter($candidate, $meter, $current)) {
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

    /**
     * Whether `$candidate` grants a strictly better entitlement for `$meter` than the org's
     * current resolved policy.
     *
     * @param  array{enabled: bool, allowance: int|null, weight: float|null, overage: string}|null  $current
     */
    private function improvesMeter(Plan $candidate, string $meter, ?array $current): bool
    {
        $entitlement = $this->entitlementFor($candidate, $meter);

        // The candidate must itself grant the meter (enabled, with headroom or unlimited).
        if (! $entitlement instanceof PlanEntitlement || ! $entitlement->enabled) {
            return false;
        }

        if (! $entitlement->unlimited && $entitlement->allowance <= 0) {
            return false;
        }

        // Absent or disabled today → any grant is an improvement.
        if ($current === null || $current['enabled'] !== true) {
            return true;
        }

        // Enabled + finite today (the unlimited case returned early): unlimited or a larger
        // allowance is an improvement.
        if ($entitlement->unlimited) {
            return true;
        }

        $currentAllowance = $current['allowance'];

        return $currentAllowance !== null && $entitlement->allowance > $currentAllowance;
    }

    private function entitlementFor(Plan $plan, string $meter): ?PlanEntitlement
    {
        foreach ($plan->entitlements as $entitlement) {
            if ($entitlement->meter?->key === $meter) {
                return $entitlement;
            }
        }

        return null;
    }

    /** @return Collection<int, Plan> */
    private function offeredPlans(): Collection
    {
        return Plan::query()
            ->with(['prices', 'entitlements.meter', 'product'])
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
