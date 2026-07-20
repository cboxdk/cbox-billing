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
 * The Chargebee export imports correctly despite its different field names (first_name/last_name,
 * preferred_currency_code, item_family_id, period_unit) — amounts are minor units as Stripe's are.
 */
class ImportChargebeeTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_chargebee_export_maps_its_field_names_and_units(): void
    {
        [, $plan] = $this->commitImport(ImportSource::Chargebee);
        $this->assertFalse($plan->hasConflicts());

        $planModel = Plan::query()->where('key', 'pro-monthly')->firstOrFail();
        $this->assertSame('Pro Monthly', $planModel->name);
        $this->assertSame('month', $planModel->interval);

        // Minor units (2500 cents), currency from `currency_code`.
        $price = PlanPrice::query()->where('plan_id', $planModel->id)->where('currency', 'EUR')->firstOrFail();
        $this->assertSame(2500, $price->price_minor);

        // Name split across first_name/last_name; currency from preferred_currency_code; VAT number.
        $org = Organization::query()->where('billing_email', 'bob@build.test')->firstOrFail();
        $this->assertSame('Bob Builder', $org->name);
        $this->assertSame('EUR', $org->billing_currency);
        $this->assertSame('DE', $org->billing_country);
        $this->assertSame('DE123456789', $org->tax_id);

        // plan_quantity → seats.
        $subscription = Subscription::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame(2, $subscription->seats);

        $coupon = Coupon::query()->where('code', 'SUMMER')->firstOrFail();
        $this->assertSame(20, $coupon->percent_off);

        // Invoice with tax mapped from Chargebee's sub_total / tax / total.
        $invoice = Invoice::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame(5000, $invoice->subtotal_minor);
        $this->assertSame(950, $invoice->tax_minor);
        $this->assertSame(5950, $invoice->total_minor);
    }
}
