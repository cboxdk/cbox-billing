<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;

/**
 * Read model for the plan-comparison / pricing view (#55). Projects the real catalog —
 * {@see Plan} rows with their per-currency {@see PlanPrice}s and metered entitlements — into
 * a comparison matrix (meters × plans) plus per-plan pricing cards. Everything is the
 * catalog truth: prices are the stored minor amounts per currency, entitlements are the
 * stored per-meter rows, and an inactive plan is marked `legacy` (a grandfathered plan
 * existing subscribers hold but which is no longer offered).
 */
readonly class PricingReport
{
    /**
     * @return array{
     *     currencies: list<string>,
     *     meters: list<array{key: string, name: string, unit: string}>,
     *     plans: list<array<string, mixed>>
     * }
     */
    public function comparison(?string $currency = null): array
    {
        $plans = Plan::query()
            ->with(['prices', 'entitlements.meter'])
            ->orderBy('active', 'desc')
            ->orderBy('id')
            ->get();

        $meters = array_values(Meter::query()->orderBy('id')->get()
            ->map(static fn (Meter $meter): array => [
                'key' => $meter->key,
                'name' => $meter->name,
                'unit' => $meter->unit,
            ])->all());

        $currencies = $this->currencies($plans);

        return [
            'currencies' => $currencies,
            'meters' => $meters,
            'plans' => array_values($plans
                ->map(fn (Plan $plan): array => $this->plan($plan, $meters))
                ->all()),
        ];
    }

    /**
     * The pricing cards for one currency — the shape the public-style pricing partial
     * renders. A plan not priced in `$currency` is omitted (deny-by-default, no fabricated
     * rate); legacy plans are omitted from a public pricing view but marked in the console
     * comparison.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(string $currency, bool $includeLegacy = false): array
    {
        return array_values(array_filter(
            $this->comparison($currency)['plans'],
            static function (array $plan) use ($currency, $includeLegacy): bool {
                $prices = $plan['prices'] ?? [];

                return is_array($prices) && isset($prices[$currency])
                    && ($includeLegacy || empty($plan['legacy']));
            },
        ));
    }

    /**
     * @param  list<array{key: string, name: string, unit: string}>  $meters
     * @return array<string, mixed>
     */
    private function plan(Plan $plan, array $meters): array
    {
        $prices = [];

        foreach ($plan->prices as $price) {
            $prices[$price->currency] = $price->price_minor;
        }

        $entitlements = [];

        foreach ($meters as $meter) {
            $entitlements[$meter['key']] = $this->entitlement($plan, $meter['key']);
        }

        return [
            'key' => $plan->key,
            'id' => $plan->id,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'legacy' => ! $plan->active,
            'prices' => $prices,
            'entitlements' => $entitlements,
        ];
    }

    /**
     * The plan's entitlement for one meter in the comparison shape — deny-by-default: a
     * meter the plan carries no row for reads as not included.
     *
     * @return array{included: bool, unlimited: bool, allowance: int|null, overage: string|null}
     */
    private function entitlement(Plan $plan, string $meterKey): array
    {
        foreach ($plan->entitlements as $entitlement) {
            if ($entitlement->meter?->key !== $meterKey) {
                continue;
            }

            return $this->present($entitlement);
        }

        return ['included' => false, 'unlimited' => false, 'allowance' => null, 'overage' => null];
    }

    /** @return array{included: bool, unlimited: bool, allowance: int|null, overage: string|null} */
    private function present(PlanEntitlement $entitlement): array
    {
        if (! $entitlement->enabled) {
            return ['included' => false, 'unlimited' => false, 'allowance' => null, 'overage' => null];
        }

        return [
            'included' => true,
            'unlimited' => $entitlement->unlimited,
            'allowance' => $entitlement->unlimited ? null : $entitlement->allowance,
            'overage' => $entitlement->overage->value,
        ];
    }

    /**
     * The union of currencies any plan is priced in, in a stable order.
     *
     * @param  iterable<Plan>  $plans
     * @return list<string>
     */
    private function currencies(iterable $plans): array
    {
        $seen = [];

        foreach ($plans as $plan) {
            foreach ($plan->prices as $price) {
                $seen[$price->currency] = true;
            }
        }

        $currencies = array_keys($seen);
        sort($currencies);

        return $currencies;
    }
}
