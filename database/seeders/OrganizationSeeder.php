<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A couple of demo organizations, each with an active subscription and a recent
 * invoice, so the host seams (meter-policy resolver, expected-entitlement oracle,
 * payment applier) have real data to resolve against. Organization ids mirror the
 * cbox-id `org_…` handle. Names are fictional — no real third party is referenced.
 */
class OrganizationSeeder extends Seeder
{
    private const SELLER = 'cbox-dk';

    public function run(): void
    {
        $periodStart = Carbon::parse('2026-07-01');
        $periodEnd = Carbon::parse('2026-07-31');

        foreach ($this->organizations() as $index => $definition) {
            $organization = Organization::query()->updateOrCreate(
                ['id' => $definition['id']],
                [
                    'name' => $definition['name'],
                    'billing_email' => $definition['email'],
                    'billing_country' => $definition['country'],
                ],
            );

            $plan = Plan::query()->where('key', $definition['plan'])->firstOrFail();

            Subscription::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'plan_id' => $plan->id],
                [
                    'status' => SubscriptionStatus::Active,
                    'seats' => $definition['seats'],
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => false,
                ],
            );

            $this->seedInvoice($organization->id, $plan, $definition['invoice_status'], $index, $periodStart);
        }
    }

    private function seedInvoice(string $organizationId, Plan $plan, string $status, int $index, Carbon $issuedAt): void
    {
        $number = sprintf('%s-2026-%04d', strtoupper(self::SELLER), 500 + $index);
        $total = $plan->price_minor;

        $invoice = Invoice::query()->updateOrCreate(
            ['seller' => self::SELLER, 'number' => $number],
            [
                'organization_id' => $organizationId,
                'currency' => $plan->currency,
                'subtotal_minor' => $total,
                'tax_minor' => 0,
                'total_minor' => $total,
                'status' => $status,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(14),
                'paid_at' => $status === 'paid' ? $issuedAt->copy()->addDays(2) : null,
            ],
        );

        InvoiceLine::query()->updateOrCreate(
            ['invoice_id' => $invoice->id, 'description' => $plan->name.' — monthly subscription'],
            ['quantity' => 1, 'unit_minor' => $total, 'amount_minor' => $total],
        );
    }

    /**
     * @return list<array{id: string, name: string, email: string, country: string, plan: string, seats: int, invoice_status: string}>
     */
    private function organizations(): array
    {
        return [
            ['id' => 'org_hverdag', 'name' => 'Hverdag ApS', 'email' => 'billing@hverdag.example', 'country' => 'DK', 'plan' => 'team', 'seats' => 8, 'invoice_status' => 'paid'],
            ['id' => 'org_nordwind', 'name' => 'Nordwind Media', 'email' => 'ap@nordwind.example', 'country' => 'DK', 'plan' => 'business', 'seats' => 24, 'invoice_status' => 'open'],
            ['id' => 'org_klarhed', 'name' => 'Klarhed A/S', 'email' => 'finance@klarhed.example', 'country' => 'DK', 'plan' => 'starter', 'seats' => 2, 'invoice_status' => 'paid'],
            ['id' => 'org_fjord', 'name' => 'Fjord Studio', 'email' => 'accounts@fjord.example', 'country' => 'DK', 'plan' => 'scale', 'seats' => 60, 'invoice_status' => 'open'],
        ];
    }
}
