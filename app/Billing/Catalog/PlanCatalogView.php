<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Models\Plan;
use App\Models\PlanEntitlement;

/**
 * Projects a catalog {@see Plan} into the flat, currency-resolved shape the management
 * API returns: `{key, name, interval, entitlements, price:{minor, currency}}`. The price
 * is the plan's amount in the caller's account currency (or an explicit signup currency);
 * entitlements are read straight off the plan's metered-entitlement rows, so what the
 * catalog advertises is exactly what a subscription would resolve.
 */
readonly class PlanCatalogView
{
    /**
     * @param  iterable<Plan>  $plans
     * @return list<array<string, mixed>>
     */
    public function present(iterable $plans, string $currency): array
    {
        $out = [];

        foreach ($plans as $plan) {
            $out[] = $this->presentOne($plan, $currency);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function presentOne(Plan $plan, string $currency): array
    {
        $price = $plan->priceFor($currency);

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'entitlements' => $this->entitlements($plan),
            'price' => [
                'minor' => $price->minor(),
                'currency' => $price->currency(),
            ],
        ];
    }

    /**
     * The plan's per-meter entitlements in the same shape the enforcement API's
     * `/entitlements` payload uses — deny-by-default: a disabled meter reports
     * `enabled: false`, an unlimited one a null allowance.
     *
     * @return array<string, array{enabled: bool, allowance: int|null, weight: float|null, overage: string}>
     */
    private function entitlements(Plan $plan): array
    {
        $meters = [];

        foreach ($plan->entitlements as $entitlement) {
            $meter = $entitlement->meter;

            if ($meter === null) {
                continue;
            }

            $meters[$meter->key] = $this->entitlement($entitlement);
        }

        ksort($meters);

        return $meters;
    }

    /** @return array{enabled: bool, allowance: int|null, weight: float|null, overage: string} */
    private function entitlement(PlanEntitlement $entitlement): array
    {
        if (! $entitlement->enabled) {
            return ['enabled' => false, 'allowance' => null, 'weight' => null, 'overage' => 'block'];
        }

        return [
            'enabled' => true,
            'allowance' => $entitlement->unlimited ? null : $entitlement->allowance,
            'weight' => $entitlement->multiplier,
            'overage' => $entitlement->overage->value,
        ];
    }
}
