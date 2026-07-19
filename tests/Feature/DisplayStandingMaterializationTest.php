<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Reporting\SubscriptionReport;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-3: the console display standing is materialized on `subscriptions.display_standing`,
 * maintained on the writes that change it, and MUST equal the live {@see SubscriptionStanding::of()}
 * derivation by construction. The list filters and the counts read the indexed column, so they
 * become a real `WHERE` and a single `GROUP BY` instead of whole-table loads.
 */
class DisplayStandingMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->planId = Plan::query()->where('key', 'team')->firstOrFail()->id;
    }

    public function test_the_materialized_standing_equals_the_live_derivation_in_every_state(): void
    {
        $active = $this->subscription('s_active', SubscriptionStatus::Active);
        $paused = $this->subscription('s_paused', SubscriptionStatus::Active, ['paused_at' => Carbon::now()]);
        $trialing = $this->subscription('s_trial', SubscriptionStatus::Trialing);
        $pastDue = $this->subscription('s_pastdue', SubscriptionStatus::PastDue);
        $nonRenewing = $this->subscription('s_nonren', SubscriptionStatus::Active, ['cancel_at_period_end' => true]);
        $canceled = $this->subscription('s_cancel', SubscriptionStatus::Canceled);

        // An Active subscription whose org carries an open invoice past its due date reads as
        // past_due (the org-scoped fallback), materialized when the invoice is written.
        $overdue = $this->subscription('s_overdue', SubscriptionStatus::Active);
        $this->overdueInvoice('s_overdue');

        $expected = [
            's_active' => SubscriptionStanding::ACTIVE,
            's_paused' => SubscriptionStanding::PAUSED,
            's_trial' => SubscriptionStanding::TRIALING,
            's_pastdue' => SubscriptionStanding::PAST_DUE,
            's_nonren' => SubscriptionStanding::NON_RENEWING,
            's_cancel' => SubscriptionStanding::CANCELED,
            's_overdue' => SubscriptionStanding::PAST_DUE,
        ];

        foreach ([$active, $paused, $trialing, $pastDue, $nonRenewing, $canceled, $overdue] as $subscription) {
            $org = $subscription->organization_id;
            $stored = Subscription::query()->where('organization_id', $org)->value('display_standing');
            $live = SubscriptionStanding::of($subscription->fresh()->loadMissing('organization.invoices'));

            $this->assertSame($expected[$org], $stored, "Stored standing for {$org}");
            $this->assertSame($live, $stored, "Materialized standing must equal the live derivation for {$org}");
        }
    }

    public function test_counts_are_a_single_group_by_query(): void
    {
        $this->subscription('c_a', SubscriptionStatus::Active);
        $this->subscription('c_b', SubscriptionStatus::Active);
        $this->subscription('c_t', SubscriptionStatus::Trialing);
        $this->subscription('c_x', SubscriptionStatus::Canceled);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $counts = SubscriptionStanding::counts();

        $this->assertSame(2, $counts['active']);
        $this->assertSame(1, $counts['trialing']);
        $this->assertSame(1, $counts['canceled']);
        $this->assertSame(4, $counts['all']);

        // A single grouped read of the subscriptions table — no per-row / whole-table scan.
        $subscriptionReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "subscriptions"')));
        $this->assertSame(1, $subscriptionReads);
        $this->assertStringContainsStringIgnoringCase('group by', $queries[0]);
    }

    public function test_the_list_filter_is_an_indexed_where_at_the_database(): void
    {
        $this->subscription('l_a', SubscriptionStatus::Active);
        $this->subscription('l_b', SubscriptionStatus::Active);
        $this->subscription('l_c', SubscriptionStatus::Trialing);

        $report = app(SubscriptionReport::class);

        $trialing = $report->paginate('trialing');
        $this->assertSame(1, $trialing->total());
        $this->assertSame('trialing', $trialing->items()[0]['status']);

        $active = $report->paginate('active');
        $this->assertSame(2, $active->total());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(string $org, SubscriptionStatus $status, array $overrides = []): Subscription
    {
        Organization::query()->create(['id' => $org, 'name' => strtoupper($org), 'billing_country' => 'DK']);

        return Subscription::query()->create(array_merge([
            'organization_id' => $org,
            'plan_id' => $this->planId,
            'status' => $status,
            'seats' => 3,
            'current_period_start' => Carbon::now()->startOfMonth(),
            'current_period_end' => Carbon::now()->endOfMonth(),
            'cancel_at_period_end' => false,
        ], $overrides));
    }

    private function overdueInvoice(string $org): void
    {
        Invoice::query()->create([
            'organization_id' => $org,
            'seller' => 'cbox-dk',
            'number' => 'OD-'.$org,
            'currency' => 'DKK',
            'subtotal_minor' => 10_000,
            'tax_minor' => 2_500,
            'total_minor' => 12_500,
            'status' => 'open',
            'issued_at' => Carbon::now()->subDays(30),
            'due_at' => Carbon::now()->subDays(16),
        ]);
    }
}
