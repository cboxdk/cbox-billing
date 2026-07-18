<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\Product;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Illuminate\Database\Seeder;

/**
 * A demo catalog: one product with four plans (Starter / Team / Business / Scale),
 * each priced in DKK + EUR + USD (minor units), with a recurring included-credit grant
 * and per-meter entitlements. Prices, allowances and multipliers are synthetic but
 * internally consistent so later tasks have real catalog rows to project from.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $meters = $this->seedMeters();

        $product = Product::query()->updateOrCreate(
            ['key' => 'cbox-billing'],
            ['name' => 'Cbox Billing', 'description' => 'Usage-metered billing platform.'],
        );

        foreach ($this->plans() as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'product_id' => $product->id,
                    'name' => $definition['name'],
                    'interval' => 'month',
                    'active' => $definition['active'] ?? true,
                ],
            );

            foreach ($definition['prices'] as $currency => $priceMinor) {
                PlanPrice::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'currency' => $currency],
                    ['price_minor' => $priceMinor],
                );
            }

            PlanCreditGrant::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'pool' => Pools::INCLUDED],
                [
                    'kind' => GrantKind::Base,
                    'cadence' => GrantCadence::Monthly,
                    'amount' => $definition['included_credits'],
                    'denomination' => 'credit',
                ],
            );

            foreach ($definition['entitlements'] as $meterKey => $entitlement) {
                PlanEntitlement::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'meter_id' => $meters[$meterKey]->id],
                    $entitlement,
                );
            }
        }

        $this->seedTieredPricing();
    }

    /**
     * Give a few plan prices a tiered pricing model (engine v0.8 catalog depth) so the
     * catalog console renders real tier tables. The base `price_minor` is left unchanged —
     * it stays the list recurring amount the MRR read model sums — and each tier set is the
     * per-seat schedule that price scales by. One plan per tiered model so all four are
     * exercised: Team `graduated`, Business `volume`, Scale `package`, Starter `stairstep`.
     */
    private function seedTieredPricing(): void
    {
        foreach ($this->tieredPricing() as $planKey => $spec) {
            $plan = Plan::query()->where('key', $planKey)->first();

            if (! $plan instanceof Plan) {
                continue;
            }

            foreach ($plan->prices as $price) {
                $tiers = $spec['tiers'][$price->currency] ?? null;

                if ($tiers === null) {
                    continue;
                }

                $price->forceFill([
                    'pricing_model' => $spec['model'],
                    'package_size' => $spec['package_size'] ?? null,
                ])->save();

                $price->tiers()->delete();

                foreach ($tiers as $order => $tier) {
                    PlanPriceTier::query()->create([
                        'plan_price_id' => $price->id,
                        'up_to' => $tier['up_to'],
                        'unit_minor' => $tier['unit_minor'] ?? 0,
                        'flat_minor' => $tier['flat_minor'] ?? null,
                        'sort_order' => $order,
                    ]);
                }
            }
        }
    }

    /**
     * The tiered schedules, per plan then per currency. `up_to` is the inclusive seat bound
     * (null = ∞); `unit_minor` the per-seat rate (graduated/volume); `flat_minor` the flat
     * amount (the block price for `package`, the whole-bracket price for `stairstep`).
     *
     * @return array<string, array{model: string, package_size?: int, tiers: array<string, list<array{up_to: int|null, unit_minor?: int, flat_minor?: int|null}>>}>
     */
    private function tieredPricing(): array
    {
        return [
            // Graduated: each seat slice billed at its own tier's rate.
            'team' => [
                'model' => 'graduated',
                'tiers' => [
                    'DKK' => [['up_to' => 10, 'unit_minor' => 0], ['up_to' => 50, 'unit_minor' => 9_900], ['up_to' => null, 'unit_minor' => 7_900]],
                    'EUR' => [['up_to' => 10, 'unit_minor' => 0], ['up_to' => 50, 'unit_minor' => 1_300], ['up_to' => null, 'unit_minor' => 1_050]],
                    'USD' => [['up_to' => 10, 'unit_minor' => 0], ['up_to' => 50, 'unit_minor' => 1_500], ['up_to' => null, 'unit_minor' => 1_200]],
                ],
            ],
            // Volume: every seat billed at the single tier the total lands in.
            'business' => [
                'model' => 'volume',
                'tiers' => [
                    'DKK' => [['up_to' => 25, 'unit_minor' => 12_000], ['up_to' => 100, 'unit_minor' => 9_900], ['up_to' => null, 'unit_minor' => 7_900]],
                    'EUR' => [['up_to' => 25, 'unit_minor' => 1_600], ['up_to' => 100, 'unit_minor' => 1_300], ['up_to' => null, 'unit_minor' => 1_050]],
                    'USD' => [['up_to' => 25, 'unit_minor' => 1_800], ['up_to' => 100, 'unit_minor' => 1_500], ['up_to' => null, 'unit_minor' => 1_200]],
                ],
            ],
            // Package: a block price per pack of 10 seats.
            'scale' => [
                'model' => 'package',
                'package_size' => 10,
                'tiers' => [
                    'DKK' => [['up_to' => null, 'unit_minor' => 0, 'flat_minor' => 79_000]],
                    'EUR' => [['up_to' => null, 'unit_minor' => 0, 'flat_minor' => 10_500]],
                    'USD' => [['up_to' => null, 'unit_minor' => 0, 'flat_minor' => 11_900]],
                ],
            ],
            // Stairstep: one flat price for the whole seat bracket.
            'starter' => [
                'model' => 'stairstep',
                'tiers' => [
                    'DKK' => [['up_to' => 3, 'flat_minor' => 29_000], ['up_to' => 10, 'flat_minor' => 59_000], ['up_to' => null, 'flat_minor' => 99_000]],
                    'EUR' => [['up_to' => 3, 'flat_minor' => 3_900], ['up_to' => 10, 'flat_minor' => 7_900], ['up_to' => null, 'flat_minor' => 13_500]],
                    'USD' => [['up_to' => 3, 'flat_minor' => 4_500], ['up_to' => 10, 'flat_minor' => 8_900], ['up_to' => null, 'flat_minor' => 14_900]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, Meter>
     */
    private function seedMeters(): array
    {
        $definitions = [
            'api.requests' => ['name' => 'API requests', 'unit' => 'requests'],
            'seats' => ['name' => 'Seats', 'unit' => 'seats'],
            'storage.gb' => ['name' => 'Storage', 'unit' => 'GB'],
            'events.ingested' => ['name' => 'Ingested events', 'unit' => 'events'],
        ];

        $meters = [];

        foreach ($definitions as $key => $attributes) {
            $meters[$key] = Meter::query()->updateOrCreate(['key' => $key], $attributes);
        }

        return $meters;
    }

    /**
     * The four-plan ladder. Each plan is priced in DKK + EUR + USD (minor units), and
     * each entitlement is a projection-ready {@see MeterPolicy} shape.
     *
     * @return list<array{key: string, name: string, active?: bool, prices: array<string, int>, included_credits: int, entitlements: array<string, array<string, mixed>>}>
     */
    private function plans(): array
    {
        return [
            [
                'key' => 'starter',
                'name' => 'Starter',
                'prices' => ['DKK' => 29_000, 'EUR' => 3_900, 'USD' => 4_500],
                'included_credits' => 50_000,
                'entitlements' => [
                    'api.requests' => $this->metered(100_000, 0.0005, OverageBehaviour::Bill),
                    'seats' => $this->metered(3, 0.0, OverageBehaviour::Block),
                    'storage.gb' => $this->metered(10, 0.0, OverageBehaviour::Block),
                    'events.ingested' => $this->disabled(),
                ],
            ],
            [
                'key' => 'team',
                'name' => 'Team',
                'prices' => ['DKK' => 124_000, 'EUR' => 16_900, 'USD' => 18_900],
                'included_credits' => 250_000,
                'entitlements' => [
                    'api.requests' => $this->metered(1_000_000, 0.0004, OverageBehaviour::Bill),
                    'seats' => $this->metered(10, 9_900.0, OverageBehaviour::Bill),
                    'storage.gb' => $this->metered(100, 0.0, OverageBehaviour::Block),
                    'events.ingested' => $this->metered(500_000, 0.0002, OverageBehaviour::Bill),
                ],
            ],
            [
                'key' => 'business',
                'name' => 'Business',
                'prices' => ['DKK' => 349_000, 'EUR' => 46_900, 'USD' => 52_900],
                'included_credits' => 1_000_000,
                'entitlements' => [
                    'api.requests' => $this->metered(5_000_000, 0.0003, OverageBehaviour::Bill),
                    'seats' => $this->metered(50, 7_900.0, OverageBehaviour::Bill),
                    'storage.gb' => $this->metered(1_000, 0.0, OverageBehaviour::Block),
                    'events.ingested' => $this->metered(5_000_000, 0.00015, OverageBehaviour::Bill),
                ],
            ],
            [
                'key' => 'scale',
                'name' => 'Scale',
                'prices' => ['DKK' => 990_000, 'EUR' => 132_900, 'USD' => 149_900],
                'included_credits' => 5_000_000,
                'entitlements' => [
                    'api.requests' => $this->unlimited(),
                    'seats' => $this->unlimited(),
                    'storage.gb' => $this->metered(10_000, 0.0, OverageBehaviour::Block),
                    'events.ingested' => $this->unlimited(),
                ],
            ],
            // Early Access: a demo/beta plan that LOCKS a generous included allowance behind
            // a single pinned (flat) price, so early adopters grandfather onto it — the plan
            // an operator later marks retiring to move them onto the ladder (ADR-0016). It is
            // closed to new signups (grandfathered / `active = false`): a valid transition
            // source but never offered to new customers, so it stays off the upgrade ladder.
            [
                'key' => 'early-access',
                'name' => 'Early Access',
                'active' => false,
                'prices' => ['DKK' => 49_000, 'EUR' => 6_500, 'USD' => 6_900],
                'included_credits' => 2_000_000,
                'entitlements' => [
                    'api.requests' => $this->metered(3_000_000, 0.0002, OverageBehaviour::Bill),
                    'seats' => $this->metered(25, 0.0, OverageBehaviour::Block),
                    'storage.gb' => $this->metered(500, 0.0, OverageBehaviour::Block),
                    'events.ingested' => $this->metered(2_000_000, 0.0001, OverageBehaviour::Bill),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function metered(int $allowance, float $multiplier, OverageBehaviour $overage): array
    {
        return [
            'enabled' => true,
            'allowance' => $allowance,
            'multiplier' => $multiplier === 0.0 ? null : $multiplier,
            'unlimited' => false,
            'overage' => $overage,
        ];
    }

    /** @return array<string, mixed> */
    private function unlimited(): array
    {
        return [
            'enabled' => true,
            'allowance' => 0,
            'multiplier' => null,
            'unlimited' => true,
            'overage' => OverageBehaviour::Bill,
        ];
    }

    /** @return array<string, mixed> */
    private function disabled(): array
    {
        return [
            'enabled' => false,
            'allowance' => 0,
            'multiplier' => null,
            'unlimited' => false,
            'overage' => OverageBehaviour::Block,
        ];
    }
}
