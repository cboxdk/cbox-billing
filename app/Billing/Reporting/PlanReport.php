<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\Feature;
use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\Subscription;

/**
 * Read model for the per-plan detail page — the hub the catalog editors hang off. Projects
 * one {@see Plan} with its product, per-currency {@see PlanPrice}s (and tiers), metered
 * {@see PlanEntitlement}s, {@see PlanCreditGrant}s, retirement state and live subscriber
 * counts into the detail shape. No writes.
 */
readonly class PlanReport
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $plan = Plan::query()
            ->with([
                'product',
                'prices.tiers',
                'entitlements.meter',
                'features.feature',
                'creditGrants',
                'defaultSuccessor',
            ])
            ->find($id);

        if (! $plan instanceof Plan) {
            return null;
        }

        $serving = Subscription::query()->where('plan_id', $plan->id)->serving()->count();
        $total = Subscription::query()->where('plan_id', $plan->id)->count();

        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'interval' => $plan->interval,
            'active' => $plan->active,
            'product' => $plan->product !== null
                ? ['id' => $plan->product->id, 'key' => $plan->product->key, 'name' => $plan->product->name, 'archived' => $plan->product->isArchived()]
                : null,
            'retiring' => $plan->isRetiring(),
            'retires_at' => $plan->retires_at?->format('j M Y'),
            'default_successor' => $plan->defaultSuccessor !== null
                ? ['id' => $plan->defaultSuccessor->id, 'name' => $plan->defaultSuccessor->name]
                : null,
            'currencies' => $plan->pricedCurrencies(),
            'pricing_model' => $this->model($plan),
            'serving_subscribers' => $serving,
            'total_subscribers' => $total,
            'prices' => $plan->prices
                ->sortBy('currency')
                ->map(fn (PlanPrice $price): array => $this->price($price))
                ->values()
                ->all(),
            'entitlements' => $plan->entitlements
                ->sortBy('meter_id')
                ->map(static function (PlanEntitlement $entitlement): array {
                    $meter = $entitlement->meter;

                    return [
                        'id' => $entitlement->id,
                        'meter' => $meter instanceof Meter ? $meter->label() : '—',
                        'meter_key' => $meter instanceof Meter ? $meter->key : '',
                        'unit' => $meter instanceof Meter ? $meter->unit : '',
                        'aggregation' => $meter instanceof Meter ? $meter->aggregation->value : '',
                        'enabled' => $entitlement->enabled,
                        'unlimited' => $entitlement->unlimited,
                        'allowance' => $entitlement->allowance,
                        'multiplier' => $entitlement->multiplier,
                        'overage' => $entitlement->overage->value,
                    ];
                })->values()->all(),
            'features' => $plan->features
                ->map(static function (PlanFeature $grant): array {
                    $feature = $grant->feature;

                    return [
                        'id' => $grant->id,
                        'feature' => $feature instanceof Feature ? $feature->name : '—',
                        'feature_key' => $feature instanceof Feature ? $feature->key : '',
                        'type' => $feature instanceof Feature ? $feature->type->value : '',
                        'enabled' => $grant->enabled,
                        'value' => $grant->value,
                    ];
                })
                ->sortBy('feature_key')
                ->values()
                ->all(),
            'credit_grants' => $plan->creditGrants
                ->sortBy('id')
                ->map(static fn (PlanCreditGrant $grant): array => [
                    'id' => $grant->id,
                    'pool' => $grant->pool,
                    'kind' => $grant->kind->value,
                    'cadence' => $grant->cadence->value,
                    'amount' => $grant->amount,
                    'amount_mode' => $grant->amount_mode->value,
                    'rollover_seconds' => $grant->rollover_seconds,
                    'denomination' => $grant->denomination,
                ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, currency: string, minor: int, model: string, tiered: bool, package_size: int|null, tiers: list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>}
     */
    private function price(PlanPrice $price): array
    {
        $model = $price->model();

        return [
            'id' => $price->id,
            'currency' => $price->currency,
            'minor' => $price->price_minor,
            'model' => $model->value,
            'tiered' => $model->isTiered(),
            'package_size' => $price->package_size,
            'tiers' => array_values($price->tiers
                ->sortBy('sort_order')
                ->map(static fn (PlanPriceTier $tier): array => [
                    'up_to' => $tier->up_to,
                    'unit_minor' => $tier->unit_minor,
                    'flat_minor' => $tier->flat_minor,
                ])->all()),
        ];
    }

    /** The plan's headline pricing model — shared across its prices, or `mixed`/`flat`. */
    private function model(Plan $plan): string
    {
        $models = $plan->prices
            ->map(static fn (PlanPrice $price): string => $price->model()->value)
            ->unique()
            ->values();

        return match ($models->count()) {
            0 => 'flat',
            1 => (string) $models->first(),
            default => 'mixed',
        };
    }
}
