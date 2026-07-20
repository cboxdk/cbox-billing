<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Quote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo CPQ quotes on the seeded catalog + organizations, one per interesting lifecycle state so the
 * console has something to show. Idempotent (keyed on `number`); it only adds quotes, leaving the
 * rest of the seed graph untouched. A skip guard keeps it inert when the catalog/orgs are absent.
 */
class QuoteSeeder extends Seeder
{
    public function run(): void
    {
        $team = Plan::query()->where('key', 'team')->with('prices')->first();
        $org = Organization::query()->find('org_hverdag');

        if (! $team instanceof Plan || ! $org instanceof Organization) {
            return;
        }

        // A sent quote with contract terms + commitment, out with the customer.
        $this->quote('Q-90001', $org->id, QuoteStatus::Sent, [
            'currency' => 'DKK',
            'term_count' => 12,
            'minimum_commitment_minor' => 500000,
            'valid_until' => Carbon::now()->addDays(21),
            'owner_name' => 'Sales Demo',
            'token' => 'demo-'.bin2hex(random_bytes(16)),
            'sent_at' => Carbon::now()->subDays(2),
            'approval_required' => true,
            'approved_by_name' => 'Deal Desk',
            'approved_at' => Carbon::now()->subDays(3),
        ], [
            ['type' => QuoteLineType::Plan, 'plan_id' => $team->id, 'quantity' => 25, 'recurring' => true],
            ['type' => QuoteLineType::Custom, 'description' => 'Onboarding & migration (one-off)', 'quantity' => 1, 'unit_amount_minor' => 1500000, 'recurring' => false],
        ]);

        // A draft the rep is still building.
        $this->quote('Q-90002', $org->id, QuoteStatus::Draft, [
            'currency' => 'DKK',
            'term_count' => 24,
            'billing_interval' => 'yearly',
            'owner_name' => 'Sales Demo',
        ], [
            ['type' => QuoteLineType::Plan, 'plan_id' => $team->id, 'quantity' => 10, 'recurring' => true, 'discount_kind' => 'percent', 'discount_value' => 15],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    private function quote(string $number, string $orgId, QuoteStatus $status, array $attributes, array $lines): void
    {
        $quote = Quote::query()->updateOrCreate(
            ['number' => $number],
            [
                'organization_id' => $orgId,
                'status' => $status,
                'currency' => $attributes['currency'] ?? 'DKK',
                'term_unit' => $attributes['term_unit'] ?? 'month',
                'billing_interval' => $attributes['billing_interval'] ?? 'monthly',
                'term_count' => $attributes['term_count'] ?? 12,
                'minimum_commitment_minor' => $attributes['minimum_commitment_minor'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
                'owner_name' => $attributes['owner_name'] ?? null,
                'token' => $attributes['token'] ?? null,
                'sent_at' => $attributes['sent_at'] ?? null,
                'approval_required' => $attributes['approval_required'] ?? false,
                'approved_by_name' => $attributes['approved_by_name'] ?? null,
                'approved_at' => $attributes['approved_at'] ?? null,
            ],
        );

        $quote->lines()->delete();
        $order = 0;

        foreach ($lines as $line) {
            $type = $line['type'];

            $quote->lines()->create([
                'sort_order' => $order++,
                'type' => $type,
                'plan_id' => $type === QuoteLineType::Plan ? ($line['plan_id'] ?? null) : null,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? 1,
                'unit_amount_minor' => $type === QuoteLineType::Custom ? ($line['unit_amount_minor'] ?? null) : null,
                'discount_kind' => $line['discount_kind'] ?? null,
                'discount_value' => $line['discount_value'] ?? null,
                'recurring' => $line['recurring'] ?? true,
            ]);
        }
    }
}
