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
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Illuminate\Database\Seeder;

/**
 * The Cbox Assistant SaaS catalog: three tiers mirroring the assistant app's
 * `config/plans.php` (Starter / Growth / Enterprise) with one usage meter —
 * `conversations`, the billable unit. Enterprise is sales-led: it exists so an
 * operator can assign it, but carries no self-serve price (and is therefore
 * omitted from `GET /api/v1/plans`, deny-by-default).
 *
 * Plan keys are GLOBALLY unique and this catalog coexists with other products on a
 * shared instance (cboxbilling.com), so every key carries the `assistant-` prefix —
 * the assistant app maps its local plan keys (`starter`/`growth`) to these via its
 * config/plans.php `billing_key`. Safe to run on the shared production instance.
 */
class AssistantCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $conversations = Meter::query()->updateOrCreate(
            ['key' => 'conversations'],
            ['name' => 'Conversations', 'unit' => 'conversations'],
        );

        $product = Product::query()->updateOrCreate(
            ['key' => 'cbox-assistant'],
            ['name' => 'Cbox Assistant', 'description' => 'AI-first customer support suite.'],
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
                    'amount' => $definition['included_conversations'] ?? 0,
                    'denomination' => 'credit',
                ],
            );

            PlanEntitlement::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'meter_id' => $conversations->id],
                $definition['conversations'],
            );
        }
    }

    /**
     * Mirrors the assistant's config/plans.php: included allowance = the plan's
     * `limits.monthly_conversations`, overage per conversation = the old Stripe
     * `metered_amount` (5¢ / 3¢), billed past the allowance.
     *
     * @return list<array{key: string, name: string, prices: array<string, int>, included_conversations: int|null, conversations: array<string, mixed>}>
     */
    private function plans(): array
    {
        return [
            [
                'key' => 'assistant-starter',
                'name' => 'Starter',
                'prices' => ['USD' => 4_900, 'EUR' => 4_500, 'DKK' => 34_900],
                'included_conversations' => 1_000,
                'conversations' => [
                    'enabled' => true,
                    'allowance' => 1_000,
                    'multiplier' => 0.05,
                    'unlimited' => false,
                    'overage' => OverageBehaviour::Bill,
                ],
            ],
            [
                'key' => 'assistant-growth',
                'name' => 'Growth',
                'prices' => ['USD' => 19_900, 'EUR' => 18_500, 'DKK' => 139_900],
                'included_conversations' => 10_000,
                'conversations' => [
                    'enabled' => true,
                    'allowance' => 10_000,
                    'multiplier' => 0.03,
                    'unlimited' => false,
                    'overage' => OverageBehaviour::Bill,
                ],
            ],
            [
                // Sales-led: no self-serve price → invisible in the sellable catalog.
                'key' => 'assistant-enterprise',
                'name' => 'Enterprise',
                'prices' => [],
                'included_conversations' => null,
                'conversations' => [
                    'enabled' => true,
                    'allowance' => 0, // ignored when unlimited
                    'multiplier' => null,
                    'unlimited' => true,
                    'overage' => OverageBehaviour::Bill,
                ],
            ],
        ];
    }
}
