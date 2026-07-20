<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Enums\ImportSource;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * The Recurly export is the most divergent: DECIMAL MAJOR-unit amounts ("49.00"), ISO-8601 dates,
 * `code` natural keys, accounts with no currency (pinned at subscribe). The adapter multiplies the
 * decimal amounts up to minor units, so a "49.00" plan lands as 4900 minor — not 49.
 */
class ImportRecurlyTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_recurly_decimal_major_units_convert_to_minor(): void
    {
        [, $plan] = $this->commitImport(ImportSource::Recurly);
        $this->assertFalse($plan->hasConflicts());

        $planModel = Plan::query()->where('key', 'enterprise')->firstOrFail();
        $this->assertSame('Enterprise', $planModel->name);
        $this->assertSame('month', $planModel->interval);

        // "49.00" decimal major units → 4900 minor units.
        $price = PlanPrice::query()->where('plan_id', $planModel->id)->where('currency', 'USD')->firstOrFail();
        $this->assertSame(4900, $price->price_minor);

        // A Recurly account carries no currency — the org is pinned to USD when it subscribes.
        $org = Organization::query()->where('billing_email', 'cara@corp.test')->firstOrFail();
        $this->assertSame('Cara Cole', $org->name);
        $this->assertSame('GB', $org->billing_country);
        $this->assertSame('USD', $org->billing_currency);

        $subscription = Subscription::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame($planModel->id, $subscription->plan_id);
        $this->assertSame('2024-07-01', $subscription->current_period_start?->toDateString());

        $coupon = Coupon::query()->where('code', 'LOYAL')->firstOrFail();
        $this->assertSame(15, $coupon->percent_off);

        // Recurly invoice amounts are decimal major units too → 4900 minor.
        $invoice = Invoice::query()->where('number', '1001')->firstOrFail();
        $this->assertSame(4900, $invoice->total_minor);
    }
}
