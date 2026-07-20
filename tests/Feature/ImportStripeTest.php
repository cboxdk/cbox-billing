<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Enums\ImportSource;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Models\Coupon;
use App\Models\ImportSourceRef;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * The Stripe export fixture imports into the app through the real domain services: catalog,
 * customer, subscription, coupon and a historical invoice — with correct minor-unit amounts,
 * preserved historical dates, and the source→app id ledger populated. A re-run is a no-op.
 */
class ImportStripeTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_stripe_export_imports_catalog_customer_subscription_and_invoice(): void
    {
        [$run, $plan] = $this->commitImport(ImportSource::Stripe);

        $this->assertTrue($run->isCommitted());
        $this->assertFalse($plan->hasConflicts());

        // Catalog: product, plan (interval), per-currency price (minor units).
        $product = Product::query()->where('key', 'prod_basic')->firstOrFail();
        $this->assertSame('Basic', $product->name);

        $planModel = Plan::query()->where('key', 'basic-monthly')->firstOrFail();
        $this->assertSame('month', $planModel->interval);
        $this->assertSame($product->id, $planModel->product_id);

        $price = PlanPrice::query()->where('plan_id', $planModel->id)->where('currency', 'USD')->firstOrFail();
        $this->assertSame(1500, $price->price_minor);

        // Coupon.
        $coupon = Coupon::query()->where('code', 'WELCOME10')->firstOrFail();
        $this->assertSame(10, $coupon->percent_off);

        // Customer → organization, with preserved signup date + billing profile.
        $org = Organization::query()->where('billing_email', 'ann@acme.test')->firstOrFail();
        $this->assertSame('Ann Acme', $org->name);
        $this->assertSame('USD', $org->billing_currency);
        $this->assertSame('US', $org->billing_country);
        $this->assertSame('2024-01-02', $org->created_at?->toDateString());

        // Subscription, with preserved period anchors + creation date.
        $subscription = Subscription::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($planModel->id, $subscription->plan_id);
        $this->assertSame(1, $subscription->seats);
        $this->assertSame('2024-07-01', $subscription->current_period_start?->toDateString());
        $this->assertSame('2024-08-01', $subscription->current_period_end?->toDateString());
        $this->assertSame('2024-01-02', $subscription->created_at?->toDateString());

        // The coupon was bound to the subscription.
        $this->assertDatabaseHas('subscription_coupons', [
            'subscription_id' => $subscription->id,
            'code' => 'WELCOME10',
        ]);
        $this->assertTrue(SubscriptionCoupon::query()->where('subscription_id', $subscription->id)->exists());

        // Historical invoice imported as a faithful record (number, minor totals, dates, status).
        $invoice = Invoice::query()->where('number', 'INV-0001')->firstOrFail();
        $this->assertSame($org->id, $invoice->organization_id);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(1500, $invoice->total_minor);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $invoice->lines()->count());

        // The idempotency ledger is populated for every entity.
        $this->assertNotNull(ImportSourceRef::query()->where('source', 'stripe')->where('source_type', 'customer')->where('source_id', 'cus_ann')->value('app_id'));
        $this->assertSame((string) $planModel->id, ImportSourceRef::query()->where('source', 'stripe')->where('source_type', 'plan')->where('source_id', 'price_basic_m')->value('app_id'));
        $this->assertSame((string) $invoice->id, ImportSourceRef::query()->where('source', 'stripe')->where('source_type', 'invoice')->where('source_id', 'in_ann_1')->value('app_id'));
    }

    public function test_reimporting_the_same_stripe_export_creates_nothing_new(): void
    {
        $this->commitImport(ImportSource::Stripe);

        $orgs = Organization::query()->count();
        $subs = Subscription::query()->count();
        $invoices = Invoice::query()->count();
        $plans = Plan::query()->count();
        $refs = ImportSourceRef::query()->count();

        // Second run over the identical export.
        [, $plan] = $this->commitImport(ImportSource::Stripe);

        $this->assertSame($orgs, Organization::query()->count());
        $this->assertSame($subs, Subscription::query()->count());
        $this->assertSame($invoices, Invoice::query()->count());
        $this->assertSame($plans, Plan::query()->count());
        $this->assertSame($refs, ImportSourceRef::query()->count());

        // Every entity resolved to skipped (nothing created/updated).
        foreach ($plan->counts() as $byOutcome) {
            $this->assertSame(0, $byOutcome['created'] ?? 0);
        }
    }
}
