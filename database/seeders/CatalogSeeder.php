<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\PlanPrice;
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
                    'active' => true,
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
     * @return list<array{key: string, name: string, prices: array<string, int>, included_credits: int, entitlements: array<string, array<string, mixed>>}>
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
