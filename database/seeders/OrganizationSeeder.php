<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A book of fictional organizations that exercises every console standing on REAL rows:
 * active + invoiced accounts, a fresh trial (active subscription, not yet invoiced), a
 * past-due account (an open invoice past its due date), a scheduled cancellation, and a
 * churned (canceled) account. Each carries real invoices, and several carry real usage
 * events appended to the immutable {@see EventLog} so the Usage screen reconciles from
 * the metering source of truth. No real third party is referenced.
 */
class OrganizationSeeder extends Seeder
{
    private const SELLER = 'cbox-dk';

    private const CURRENCY = 'DKK';

    private const PREFIX = 'CBOX-DK';

    private int $invoiceSeq = 500;

    public function run(): void
    {
        $periodStart = Carbon::parse('2026-07-01');
        $periodEnd = Carbon::parse('2026-07-31');

        foreach ($this->organizations() as $definition) {
            $organization = Organization::query()->updateOrCreate(
                ['id' => $definition['id']],
                [
                    'name' => $definition['name'],
                    'billing_email' => $definition['email'],
                    'billing_currency' => self::CURRENCY,
                    'billing_country' => $definition['country'],
                    'tax_id' => $definition['tax_id'] ?? null,
                ],
            );

            $plan = Plan::query()->where('key', $definition['plan'])->firstOrFail();

            [$subStart, $subEnd] = ($definition['trial'] ?? false)
                ? [Carbon::parse('2026-07-10'), Carbon::parse('2026-08-09')]
                : [$periodStart, $periodEnd];

            Subscription::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'plan_id' => $plan->id],
                [
                    'status' => ($definition['canceled'] ?? false) ? SubscriptionStatus::Canceled : SubscriptionStatus::Active,
                    'seats' => $definition['seats'],
                    'current_period_start' => $subStart,
                    'current_period_end' => $subEnd,
                    'cancel_at_period_end' => $definition['cancel_at_period_end'] ?? false,
                    'created_at' => Carbon::parse($definition['created']),
                ],
            );

            foreach ($definition['invoices'] as $invoice) {
                $this->seedInvoice($organization->id, $plan, $invoice);
            }

            $this->seedUsage($organization->id, $definition['usage'] ?? []);
        }
    }

    /**
     * @param  array{month: string, status: string, due_days?: int}  $spec
     */
    private function seedInvoice(string $organizationId, Plan $plan, array $spec): void
    {
        $number = sprintf('%s-2026-%04d', self::PREFIX, ++$this->invoiceSeq);
        $total = $plan->priceFor(self::CURRENCY)->minor();
        $issuedAt = Carbon::parse($spec['month']);
        $dueAt = $issuedAt->copy()->addDays($spec['due_days'] ?? 14);
        $isDraft = $spec['status'] === 'draft';

        $invoice = Invoice::query()->updateOrCreate(
            ['seller' => self::SELLER, 'number' => $number],
            [
                'organization_id' => $organizationId,
                'currency' => self::CURRENCY,
                'subtotal_minor' => $total,
                'tax_minor' => 0,
                'total_minor' => $total,
                'status' => $spec['status'],
                'issued_at' => $isDraft ? null : $issuedAt,
                'due_at' => $isDraft ? null : $dueAt,
                'paid_at' => $spec['status'] === 'paid' ? $issuedAt->copy()->addDays(2) : null,
            ],
        );

        InvoiceLine::query()->updateOrCreate(
            ['invoice_id' => $invoice->id, 'description' => $plan->name.' — monthly subscription'],
            ['quantity' => 1, 'unit_minor' => $total, 'amount_minor' => $total],
        );
    }

    /**
     * Append per-meter usage totals to the immutable event log within the org's current
     * period, so the Usage screen reconciles used-vs-allowance from real events.
     *
     * @param  array<string, int>  $usage
     */
    private function seedUsage(string $organizationId, array $usage): void
    {
        if ($usage === []) {
            return;
        }

        $occurredAt = (int) (Carbon::parse('2026-07-14 12:00:00')->getTimestamp() * 1000);

        $events = [];

        foreach ($usage as $meter => $value) {
            $events[] = new UsageEvent(
                id: sprintf('seed-%s-%s', $organizationId, $meter),
                org: $organizationId,
                meter: $meter,
                service: 'seed',
                value: $value,
                occurredAt: $occurredAt,
            );
        }

        app(EventLog::class)->append($events);
    }

    /**
     * @return list<array{
     *     id: string, name: string, email: string, country: string, plan: string, seats: int, created: string,
     *     invoices: list<array{month: string, status: string, due_days?: int}>,
     *     tax_id?: string, trial?: bool, canceled?: bool, cancel_at_period_end?: bool, usage?: array<string, int>
     * }>
     */
    private function organizations(): array
    {
        return [
            [
                'id' => 'org_hverdag', 'name' => 'Hverdag ApS', 'email' => 'billing@hverdag.example',
                'country' => 'DK', 'tax_id' => 'DK12345674', 'plan' => 'team', 'seats' => 8, 'created' => '2025-11-02',
                'invoices' => [
                    ['month' => '2026-06-01', 'status' => 'paid'],
                    ['month' => '2026-07-01', 'status' => 'paid'],
                ],
                'usage' => ['api.requests' => 820_000, 'events.ingested' => 540_000, 'storage.gb' => 40, 'seats' => 8],
            ],
            [
                'id' => 'org_nordwind', 'name' => 'Nordwind Media', 'email' => 'ap@nordwind.example',
                'country' => 'DK', 'plan' => 'business', 'seats' => 24, 'created' => '2025-08-19',
                'invoices' => [
                    ['month' => '2026-06-20', 'status' => 'open', 'due_days' => 14],
                ],
                'usage' => ['api.requests' => 2_100_000, 'events.ingested' => 3_000_000, 'storage.gb' => 300, 'seats' => 24],
            ],
            [
                'id' => 'org_klarhed', 'name' => 'Klarhed A/S', 'email' => 'finance@klarhed.example',
                'country' => 'DK', 'plan' => 'starter', 'seats' => 2, 'created' => '2026-02-11',
                'invoices' => [
                    ['month' => '2026-07-01', 'status' => 'paid'],
                    ['month' => '2026-08-01', 'status' => 'draft'],
                ],
                'usage' => ['api.requests' => 61_000, 'storage.gb' => 4, 'seats' => 2],
            ],
            [
                'id' => 'org_fjord', 'name' => 'Fjord Studio', 'email' => 'accounts@fjord.example',
                'country' => 'DK', 'plan' => 'scale', 'seats' => 60, 'created' => '2024-06-30',
                'invoices' => [
                    ['month' => '2026-06-01', 'status' => 'paid'],
                    ['month' => '2026-07-01', 'status' => 'open', 'due_days' => 25],
                ],
                'usage' => ['api.requests' => 12_000_000, 'events.ingested' => 8_000_000, 'storage.gb' => 4_200, 'seats' => 60],
            ],
            [
                'id' => 'org_aula', 'name' => 'Aula Labs', 'email' => 'hello@aula.example',
                'country' => 'DK', 'plan' => 'starter', 'seats' => 2, 'created' => '2026-07-10', 'trial' => true,
                'invoices' => [],
                'usage' => ['api.requests' => 12_400, 'storage.gb' => 1, 'seats' => 2],
            ],
            [
                'id' => 'org_meridian', 'name' => 'Meridian Labs', 'email' => 'billing@meridian.example',
                'country' => 'DK', 'plan' => 'business', 'seats' => 18, 'created' => '2025-05-14',
                'invoices' => [
                    ['month' => '2026-07-01', 'status' => 'paid'],
                ],
                'usage' => ['api.requests' => 4_800_000, 'events.ingested' => 200_000, 'storage.gb' => 120, 'seats' => 18],
            ],
            [
                'id' => 'org_vinter', 'name' => 'Vinter & Co', 'email' => 'accounts@vinter.example',
                'country' => 'DK', 'plan' => 'team', 'seats' => 6, 'created' => '2025-03-14', 'cancel_at_period_end' => true,
                'invoices' => [
                    ['month' => '2026-07-01', 'status' => 'paid'],
                ],
                'usage' => ['api.requests' => 410_000, 'events.ingested' => 120_000, 'storage.gb' => 22, 'seats' => 6],
            ],
            [
                'id' => 'org_soder', 'name' => 'Söder Studio', 'email' => 'billing@soder.example',
                'country' => 'DK', 'plan' => 'starter', 'seats' => 3, 'created' => '2025-09-01', 'canceled' => true,
                'invoices' => [
                    ['month' => '2026-05-01', 'status' => 'paid'],
                ],
            ],
        ];
    }
}
