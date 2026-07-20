<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\BillingImporter;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\ValueObjects\PlanMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * PERF-2: the import dry-run resolved each record's "already imported?" check with a query per
 * row against the idempotency ledger (an N+1 that grew with the export). The walk now preloads the
 * whole ledger once, so the ref-lookup query count is bounded regardless of how many records the
 * export carries.
 */
class ImportDryRunPerfTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_dry_run_ledger_lookups_are_bounded_regardless_of_record_count(): void
    {
        $small = $this->refQueriesForDryRun(customers: 3);
        $large = $this->refQueriesForDryRun(customers: 60);

        // One snapshot query drives every row's idempotency check — a 20x larger export issues the
        // same (bounded) number of ledger queries, not one per record.
        $this->assertLessThanOrEqual(2, $small, "A small dry-run issued {$small} ledger queries.");
        $this->assertSame($small, $large, "Ledger queries grew with record count (small={$small}, large={$large}).");
    }

    private function refQueriesForDryRun(int $customers): int
    {
        $export = SourceExport::fromCombinedJson((string) json_encode($this->syntheticExport($customers)));
        $dataset = $this->importDataset(ImportSource::Stripe, $export);

        $count = 0;
        DB::listen(static function ($query) use (&$count): void {
            if (str_contains($query->sql, 'from "import_source_refs"')) {
                $count++;
            }
        });

        app(BillingImporter::class)->plan(ImportSource::Stripe, $dataset, new PlanMapping);

        return $count;
    }

    /**
     * A Stripe-shaped export with one product/price and `$customers` customers each with a
     * subscription — enough rows that an N+1 ledger lookup would be obvious.
     *
     * @return array<string, mixed>
     */
    private function syntheticExport(int $customers): array
    {
        $customerRows = [];
        $subscriptionRows = [];

        for ($i = 0; $i < $customers; $i++) {
            $customerRows[] = [
                'id' => 'cus_'.$i, 'name' => 'Cust '.$i, 'email' => 'cust'.$i.'@x.test',
                'currency' => 'usd', 'address' => ['country' => 'US'], 'created' => 1704153600,
            ];
            $subscriptionRows[] = [
                'id' => 'sub_'.$i, 'customer' => 'cus_'.$i, 'status' => 'active', 'quantity' => 1, 'currency' => 'usd',
                'items' => ['data' => [['price' => ['id' => 'price_m'], 'quantity' => 1]]],
                'current_period_start' => 1719792000, 'current_period_end' => 1722470400, 'created' => 1704153600,
            ];
        }

        return [
            'products' => [['id' => 'prod_x', 'name' => 'X', 'created' => 1704067200]],
            'prices' => [['id' => 'price_m', 'product' => 'prod_x', 'unit_amount' => 1500, 'currency' => 'usd', 'recurring' => ['interval' => 'month'], 'created' => 1704067200]],
            'customers' => $customerRows,
            'subscriptions' => $subscriptionRows,
        ];
    }
}
