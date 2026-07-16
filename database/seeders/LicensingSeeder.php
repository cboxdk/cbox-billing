<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The self-hosted product and its licensable plans. These plan keys match the license
 * profiles declared in `config/billing.php` (`billing.licensing.profiles`), so an org
 * subscribed to one of them can be minted an on-prem license by `billing:issue-licenses`
 * or the console. Prices are synthetic demo minor-unit amounts, internally consistent with
 * the rest of the catalog.
 */
class LicensingSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()->updateOrCreate(
            ['key' => 'cbox-self-hosted'],
            ['name' => 'Cbox Self-Hosted', 'description' => 'On-prem, offline-verifiable licensing for self-hosted deployments.'],
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
        }
    }

    /**
     * @return list<array{key: string, name: string, prices: array<string, int>}>
     */
    private function plans(): array
    {
        return [
            [
                'key' => 'team-onprem',
                'name' => 'Team (self-hosted)',
                'prices' => ['DKK' => 745_000, 'EUR' => 99_900, 'USD' => 109_900],
            ],
            [
                'key' => 'enterprise-onprem',
                'name' => 'Enterprise (self-hosted)',
                'prices' => ['DKK' => 2_240_000, 'EUR' => 299_900, 'USD' => 329_900],
            ],
        ];
    }
}
