<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Coupons\CouponAuthoring;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Coupons\ValueObjects\CouponDraft;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use Cbox\Billing\Pricing\CouponApplier;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coupons / discounts / promo codes exercised through the real money path: the engine
 * {@see CouponApplier} discounts the net, the quote builder taxes the
 * reduced net, and the invoice carries an engine-taxed discount line — preview == charge.
 * Every money assertion is in exact minor units.
 */
class CouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $this->product = Product::query()->create(['key' => 'app', 'name' => 'App']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_percentage_coupon_discounts_the_net_via_the_engine_and_shows_on_the_invoice(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_pct', $plan);
        $coupon = $this->coupon(['code' => 'SAVE20', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 20]);

        app(CouponRedeemer::class)->redeem($coupon, $subscription);

        $invoice = $this->invoice($subscription);

        // 20% off 10 000 net = 8 000 net (the engine applier's proratedBy(80,100)); DK 25% VAT
        // taxes the DISCOUNTED net → 2 000 tax, 10 000 gross.
        $this->assertSame(8_000, $invoice->subtotal_minor);
        $this->assertSame(2_000, $invoice->tax_minor);
        $this->assertSame(10_000, $invoice->total_minor);

        // A real, engine-taxed discount LINE — not a hand-subtracted total.
        $discountLine = $invoice->lines()->where('net_minor', '<', 0)->first();
        $this->assertInstanceOf(InvoiceLine::class, $discountLine);
        $this->assertSame(-2_000, $discountLine->net_minor);
        $this->assertStringContainsString('SAVE20', (string) $discountLine->description);
    }

    public function test_a_fixed_amount_coupon_discounts_the_net(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_fix', $plan);
        $coupon = $this->coupon([
            'code' => 'OFF25',
            'kind' => CouponDiscountKind::FixedAmount,
            'amountOffMinor' => 2_500,
            'currency' => 'DKK',
        ]);

        app(CouponRedeemer::class)->redeem($coupon, $subscription);
        $invoice = $this->invoice($subscription);

        // 2 500 off 10 000 net = 7 500 net; DK 25% VAT → 1 875 tax, 9 375 gross.
        $this->assertSame(7_500, $invoice->subtotal_minor);
        $this->assertSame(1_875, $invoice->tax_minor);
        $this->assertSame(9_375, $invoice->total_minor);
    }

    public function test_a_preview_quote_shows_the_same_discount_the_charge_collects(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_prev', $plan);
        $coupon = $this->coupon(['code' => 'SAVE20', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 20]);
        app(CouponRedeemer::class)->redeem($coupon, $subscription);

        // preview == charge: the quote's due-now equals the invoice's total.
        $quote = app(GeneratesInvoices::class)->quoteFor($subscription->fresh(['plan', 'coupon', 'organization']));
        $this->assertSame(8_000, $quote->totals->net->minor());
        $this->assertSame(10_000, $quote->totals->gross->minor());

        $invoice = $this->invoice($subscription);
        $this->assertSame($quote->totals->gross->minor(), $invoice->total_minor);
    }

    public function test_a_once_coupon_discounts_only_the_first_invoice(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_once', $plan);
        $coupon = $this->coupon([
            'code' => 'FIRST20',
            'kind' => CouponDiscountKind::Percent,
            'percentOff' => 20,
            'duration' => CouponDuration::Once,
        ]);
        app(CouponRedeemer::class)->redeem($coupon, $subscription);

        // Invoice #1: discounted. Then a renewal period: full price.
        $first = $this->invoiceForPeriod($subscription, '2026-07-01', '2026-08-01');
        $this->assertSame(8_000, $first->subtotal_minor);

        $renewal = $this->invoiceForPeriod($subscription, '2026-08-01', '2026-09-01');
        $this->assertSame(10_000, $renewal->subtotal_minor);
    }

    public function test_a_repeating_coupon_discounts_the_next_n_invoices_then_stops(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_rep', $plan);
        $coupon = $this->coupon([
            'code' => 'REP3',
            'kind' => CouponDiscountKind::Percent,
            'percentOff' => 20,
            'duration' => CouponDuration::Repeating,
            'durationInPeriods' => 3,
        ]);
        app(CouponRedeemer::class)->redeem($coupon, $subscription);

        $periods = [
            ['2026-07-01', '2026-08-01', 8_000],
            ['2026-08-01', '2026-09-01', 8_000],
            ['2026-09-01', '2026-10-01', 8_000],
            ['2026-10-01', '2026-11-01', 10_000], // 4th: repeating budget spent
        ];

        foreach ($periods as [$start, $end, $expected]) {
            $invoice = $this->invoiceForPeriod($subscription, $start, $end);
            $this->assertSame($expected, $invoice->subtotal_minor, "period {$start} net");
        }

        $this->assertSame(0, $subscription->coupon()->first()?->remaining_periods);
    }

    public function test_a_forever_coupon_discounts_every_renewal_and_reduces_mrr(): void
    {
        $plan = $this->plan(10_000);
        $subscription = $this->subscription('org_forever', $plan);
        $coupon = $this->coupon([
            'code' => 'ALWAYS20',
            'kind' => CouponDiscountKind::Percent,
            'percentOff' => 20,
            'duration' => CouponDuration::Forever,
        ]);
        app(CouponRedeemer::class)->redeem($coupon, $subscription);

        foreach ([['2026-07-01', '2026-08-01'], ['2026-08-01', '2026-09-01'], ['2026-09-01', '2026-10-01']] as [$start, $end]) {
            $invoice = $this->invoiceForPeriod($subscription, $start, $end);
            $this->assertSame(8_000, $invoice->subtotal_minor);
        }

        // MRR reflects the forever discount: 10 000 recurring net of 20% = 8 000.
        $this->assertSame(8_000, SubscriptionRevenue::monthly($subscription->fresh(['plan', 'coupon', 'organization']))->minor());
    }

    public function test_an_inactive_or_expired_or_over_limit_or_inapplicable_code_is_refused(): void
    {
        $plan = $this->plan(10_000);
        $other = $this->plan(5_000, 'other');
        $subscription = $this->subscription('org_deny', $plan);
        $redeemer = app(CouponRedeemer::class);

        $inactive = $this->coupon(['code' => 'OFFLINE', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 10, 'active' => false]);
        $this->assertDenied(fn () => $redeemer->validate('OFFLINE', $plan, 'DKK', 'org_deny'));

        $expired = $this->coupon(['code' => 'GONE', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 10, 'redeemBy' => Carbon::parse('2026-07-01', 'UTC')]);
        $this->assertDenied(fn () => $redeemer->validate('GONE', $plan, 'DKK', 'org_deny'));

        $capped = $this->coupon(['code' => 'CAP1', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 10, 'maxRedemptions' => 1]);
        $redeemer->redeem($capped, $subscription);
        $this->assertDenied(fn () => $redeemer->validate('CAP1', $plan, 'DKK', 'org_deny'));

        $scoped = $this->coupon([
            'code' => 'ONLYOTHER',
            'kind' => CouponDiscountKind::Percent,
            'percentOff' => 10,
            'scope' => CouponScope::Plans,
            'planKeys' => [$other->key],
        ]);
        $this->assertDenied(fn () => $redeemer->validate('ONLYOTHER', $plan, 'DKK', 'org_deny'));

        // Unknown code.
        $this->assertDenied(fn () => $redeemer->validate('NOPE', $plan, 'DKK', 'org_deny'));
    }

    public function test_redemption_locks_the_coupon_row_and_never_exceeds_max_redemptions(): void
    {
        $planA = $this->plan(10_000);
        $subA = $this->subscription('org_lock_a', $planA);
        $subB = $this->subscription('org_lock_b', $planA);
        $coupon = $this->coupon(['code' => 'ONE', 'kind' => CouponDiscountKind::Percent, 'percentOff' => 10, 'maxRedemptions' => 1]);

        $redeemer = app(CouponRedeemer::class);

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $redeemer->redeem($coupon, $subA);

        // The redeem re-reads the coupon row under a lock before the cap check (a FOR UPDATE
        // COUNT over the redemption rows locks nothing at zero; serializing on the stable
        // coupon row is the fix). SQLite's grammar omits the FOR UPDATE suffix, so — as the
        // seat lock test does — assert the by-id re-read is issued.
        $lockRead = array_filter($sql, static fn (string $q): bool => str_contains($q, 'from "coupons"') && str_contains($q, '"id" ='));
        $this->assertNotEmpty($lockRead, 'redeem() must re-read the coupon row to serialize the cap check.');

        // The single redemption is taken; a second is refused (the cap holds).
        $this->assertDenied(fn () => $redeemer->redeem($coupon->fresh(), $subB));
        $this->assertSame(1, $coupon->fresh()?->times_redeemed);
    }

    // --- helpers ---------------------------------------------------------

    private function assertDenied(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected the redemption to be refused.');
        } catch (CouponRedemptionDenied) {
            $this->addToAssertionCount(1);
        }
    }

    private function plan(int $priceMinor, string $key = 'main'): Plan
    {
        $plan = Plan::query()->create([
            'product_id' => $this->product->id,
            'key' => $key.'_'.uniqid(),
            'name' => 'Plan '.$key,
            'interval' => 'month',
            'active' => true,
        ]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => $priceMinor]);

        return $plan;
    }

    private function subscription(string $org, Plan $plan): Subscription
    {
        Organization::query()->create(['id' => $org, 'name' => $org, 'billing_country' => 'DK', 'billing_currency' => 'DKK']);

        return Subscription::query()->create([
            'organization_id' => $org,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(array $overrides): Coupon
    {
        $draft = new CouponDraft(
            code: $overrides['code'],
            name: $overrides['name'] ?? null,
            kind: $overrides['kind'],
            percentOff: $overrides['percentOff'] ?? null,
            amountOffMinor: $overrides['amountOffMinor'] ?? null,
            currency: $overrides['currency'] ?? null,
            duration: $overrides['duration'] ?? CouponDuration::Once,
            durationInPeriods: $overrides['durationInPeriods'] ?? null,
            maxRedemptions: $overrides['maxRedemptions'] ?? null,
            maxRedemptionsPerCustomer: $overrides['maxRedemptionsPerCustomer'] ?? null,
            redeemBy: $overrides['redeemBy'] ?? null,
            scope: $overrides['scope'] ?? CouponScope::All,
            planKeys: $overrides['planKeys'] ?? [],
            active: $overrides['active'] ?? true,
        );

        return app(CouponAuthoring::class)->create($draft);
    }

    private function invoice(Subscription $subscription): Invoice
    {
        return app(GeneratesInvoices::class)->generate($subscription->fresh(['plan', 'coupon', 'organization']));
    }

    private function invoiceForPeriod(Subscription $subscription, string $start, string $end): Invoice
    {
        $subscription->forceFill([
            'current_period_start' => Carbon::parse($start, 'UTC'),
            'current_period_end' => Carbon::parse($end, 'UTC'),
        ])->save();

        return app(GeneratesInvoices::class)->generate($subscription->fresh(['plan', 'coupon', 'organization']));
    }
}
