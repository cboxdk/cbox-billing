<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportOutcome;
use App\Billing\Import\Enums\ImportSource;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * The dry-run resolves the whole export and surfaces conflicts WITHOUT writing anything, so an
 * operator resolves an unmapped currency / a duplicate email before any commit.
 */
class ImportDryRunAndConflictsTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_dry_run_writes_nothing(): void
    {
        $plan = $this->planImport(ImportSource::Stripe);

        $this->assertNotEmpty($plan->actions);
        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, Plan::query()->count());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_dry_run_surfaces_missing_currency_and_duplicate_email_conflicts(): void
    {
        // An existing org already owns the email the export's customer carries.
        Organization::query()->create(['id' => 'org_existing', 'name' => 'Existing', 'billing_email' => 'dup@x.test']);

        $export = SourceExport::fromCombinedJson((string) json_encode([
            'products' => [['id' => 'prod_x', 'name' => 'X']],
            // A price with NO currency — cannot make a priceable plan.
            'prices' => [['id' => 'price_nocur', 'product' => 'prod_x', 'unit_amount' => 500, 'recurring' => ['interval' => 'month']]],
            'customers' => [['id' => 'cus_dup', 'name' => 'Dup', 'email' => 'dup@x.test']],
        ]));

        $plan = $this->planImport(ImportSource::Stripe, data: $this->importDataset(ImportSource::Stripe, $export));

        $conflicts = $plan->conflicts();
        $this->assertNotEmpty($conflicts);

        $planConflict = collect($conflicts)->firstWhere('entity', ImportEntityType::Plan);
        $this->assertNotNull($planConflict);
        $this->assertStringContainsString('currency', strtolower((string) $planConflict->message));

        $customerConflict = collect($conflicts)->firstWhere('entity', ImportEntityType::Customer);
        $this->assertNotNull($customerConflict);
        $this->assertSame(ImportOutcome::Conflict, $customerConflict->outcome);
        $this->assertStringContainsString('dup@x.test', (string) $customerConflict->message);

        // Nothing was written by the dry-run.
        $this->assertSame(1, Organization::query()->count()); // only the pre-existing one.
    }
}
