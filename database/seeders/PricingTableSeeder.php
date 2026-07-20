<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\Models\PricingTable;
use App\Models\PricingTableFeature;
use App\Models\PricingTablePlan;
use App\Models\Product;
use Cbox\License\Capabilities;
use Illuminate\Database\Seeder;

/**
 * A demo embeddable pricing table (#57) on the seeded catalog. To make the monthly/yearly toggle
 * real, it first mints a yearly-priced sibling for each of the four ladder plans (flat annual =
 * 10× the monthly amount — two months free), copying their feature grants and metered
 * entitlements so an annual column is a genuine, buyable plan. It then assembles the public
 * "Plans & pricing" table: four columns (Team featured + badged), presented in DKK/EUR/USD with
 * the interval toggle, and a feature-comparison matrix drawn from the catalog features.
 *
 * Additive and idempotent (all `updateOrCreate`): it only adds yearly plans + the table, so the
 * base catalog the rest of the suite projects from is untouched.
 */
class PricingTableSeeder extends Seeder
{
    /** Monthly ladder plan key => the annual sibling's marketing column config. */
    private const array LADDER = [
        'starter' => ['badge' => null, 'highlight' => 'For side projects and trials', 'featured' => false],
        'team' => ['badge' => 'Most popular', 'highlight' => 'For growing teams', 'featured' => true],
        'business' => ['badge' => null, 'highlight' => 'For scaling companies', 'featured' => false],
        'scale' => ['badge' => null, 'highlight' => 'For high-volume platforms', 'featured' => false],
    ];

    /** The feature rows of the comparison matrix, in display order. */
    private const array MATRIX = [
        Capabilities::SSO,
        Capabilities::SAML,
        Capabilities::SCIM,
        Capabilities::ANALYTICS,
        Capabilities::COMPLIANCE,
        Capabilities::SUPPORT,
        'custom_domains',
        'api_access',
        'max_projects',
    ];

    public function run(): void
    {
        $product = Product::query()->where('key', 'cbox-billing')->first();

        if (! $product instanceof Product) {
            return;
        }

        $table = PricingTable::query()->updateOrCreate(
            ['key' => 'plans'],
            [
                'name' => 'Plans & pricing',
                'seller_entity_id' => null,
                'currencies' => ['EUR', 'USD', 'DKK'],
                'default_currency' => 'EUR',
                'interval_toggle' => true,
                'cta_label' => 'Get started',
                'cta_url_template' => null,
                'active' => true,
            ],
        );

        $table->columns()->delete();
        $table->featureRows()->delete();

        $order = 0;

        foreach (self::LADDER as $planKey => $column) {
            $monthly = Plan::query()->with(['prices', 'features', 'entitlements'])->where('key', $planKey)->first();

            if (! $monthly instanceof Plan) {
                continue;
            }

            $annual = $this->seedYearlySibling($product->id, $monthly);

            PricingTablePlan::query()->create([
                'pricing_table_id' => $table->id,
                'plan_id' => $monthly->id,
                'annual_plan_id' => $annual->id,
                'sort_order' => $order++,
                'featured' => $column['featured'],
                'badge' => $column['badge'],
                'highlight' => $column['highlight'],
            ]);
        }

        $this->seedMatrix($table);
    }

    private function seedYearlySibling(int $productId, Plan $monthly): Plan
    {
        $annual = Plan::query()->updateOrCreate(
            ['key' => $monthly->key.'-yearly'],
            [
                'product_id' => $productId,
                'name' => $monthly->name,
                'interval' => 'year',
                'active' => true,
            ],
        );

        foreach ($monthly->prices as $price) {
            PlanPrice::query()->updateOrCreate(
                ['plan_id' => $annual->id, 'currency' => $price->currency],
                ['price_minor' => $price->price_minor * 10, 'pricing_model' => 'flat', 'package_size' => null],
            );
        }

        foreach ($monthly->features as $grant) {
            PlanFeature::query()->updateOrCreate(
                ['plan_id' => $annual->id, 'feature_id' => $grant->feature_id],
                ['enabled' => $grant->enabled, 'value' => $grant->value],
            );
        }

        foreach ($monthly->entitlements as $entitlement) {
            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $annual->id, 'meter_id' => $entitlement->meter_id],
                [
                    'enabled' => $entitlement->enabled,
                    'allowance' => $entitlement->allowance,
                    'multiplier' => $entitlement->multiplier,
                    'unlimited' => $entitlement->unlimited,
                    'overage' => $entitlement->overage,
                ],
            );
        }

        return $annual;
    }

    private function seedMatrix(PricingTable $table): void
    {
        $order = 0;

        foreach (self::MATRIX as $featureKey) {
            $feature = Feature::query()->where('key', $featureKey)->first();

            if (! $feature instanceof Feature) {
                continue;
            }

            PricingTableFeature::query()->create([
                'pricing_table_id' => $table->id,
                'feature_id' => $feature->id,
                'sort_order' => $order++,
            ]);
        }
    }
}
