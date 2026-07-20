<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\ValueObjects\PlanMapping;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * Plan mapping: an operator mapping routes a source plan onto a chosen app plan; a subscription
 * whose plan can be resolved neither by a mapping, an import, nor an existing app plan is flagged
 * as a conflict — never invented.
 */
class ImportPlanMappingTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    private function existingPlan(): Plan
    {
        $product = Product::query()->create(['key' => 'exist', 'name' => 'Exist']);
        $plan = Plan::query()->create(['product_id' => $product->id, 'key' => 'existing-pro', 'name' => 'Existing Pro', 'interval' => 'month', 'active' => true]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'USD', 'price_minor' => 2000, 'pricing_model' => 'flat']);

        return $plan;
    }

    public function test_operator_mapping_routes_a_source_plan_to_the_chosen_app_plan(): void
    {
        $appPlan = $this->existingPlan();

        // The subscription references a source plan that is NOT in the export catalog — only the
        // operator mapping can resolve it.
        $export = SourceExport::fromCombinedJson((string) json_encode([
            'customers' => [['id' => 'cus_m', 'name' => 'Mapped Co', 'email' => 'map@co.test', 'currency' => 'usd']],
            'subscriptions' => [[
                'id' => 'sub_m', 'customer' => 'cus_m', 'status' => 'active', 'currency' => 'usd',
                'items' => ['data' => [['price' => ['id' => 'src_plan_A'], 'quantity' => 1]]],
            ]],
        ]));

        $data = $this->importDataset(ImportSource::Stripe, $export);
        $mapping = new PlanMapping(['src_plan_A' => (string) $appPlan->id]);

        [, $plan] = $this->commitImport(ImportSource::Stripe, $mapping, $data);

        $this->assertFalse($plan->hasConflicts());
        $subscription = Subscription::query()->where('organization_id', 'imp_stripe_cus_m')->firstOrFail();
        $this->assertSame($appPlan->id, $subscription->plan_id);

        // No new plan was invented — only the one we set up exists.
        $this->assertSame(1, Plan::query()->count());
    }

    public function test_an_unmapped_source_plan_is_flagged_not_invented(): void
    {
        $this->existingPlan();

        $export = SourceExport::fromCombinedJson((string) json_encode([
            'customers' => [['id' => 'cus_u', 'name' => 'Unmapped Co', 'email' => 'u@co.test', 'currency' => 'usd']],
            'subscriptions' => [[
                'id' => 'sub_u', 'customer' => 'cus_u', 'status' => 'active', 'currency' => 'usd',
                'items' => ['data' => [['price' => ['id' => 'src_plan_UNKNOWN'], 'quantity' => 1]]],
            ]],
        ]));

        $data = $this->importDataset(ImportSource::Stripe, $export);

        // No mapping provided → the subscription's plan cannot be resolved.
        [, $plan] = $this->commitImport(ImportSource::Stripe, new PlanMapping, $data);

        $subConflict = collect($plan->conflicts())->firstWhere('entity', ImportEntityType::Subscription);
        $this->assertNotNull($subConflict);
        $this->assertStringContainsString('unmapped', strtolower((string) $subConflict->message));

        // The unknown plan was NOT invented, and no subscription was created for it.
        $this->assertSame(1, Plan::query()->count());
        $this->assertSame(0, Subscription::query()->count());
    }
}
