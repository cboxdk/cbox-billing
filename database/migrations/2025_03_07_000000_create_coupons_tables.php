<?php

declare(strict_types=1);

use App\Models\Coupon;
use Cbox\Billing\Pricing\CouponApplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COUPONS / DISCOUNTS / PROMO CODES — the money-off primitive surfaced end to end.
 *
 * A `coupon` is an authored discount (a percentage, or a fixed amount off a net taxable
 * amount) redeemable at checkout / subscribe. The discount MATH is the engine's
 * {@see CouponApplier} + {@see Cbox\Billing\Pricing\ValueObjects\Coupon}
 * ({@see Coupon::toEngineCoupon()}); these tables only model the app-side
 * lifecycle the engine primitive has no concept of: redemption limits, an expiry, a
 * plan-scope, and — crucially — the DURATION (`once` / `repeating` / `forever`) that binds a
 * discount to a subscription across renewals.
 *
 * `coupon_redemptions` is the append-only ledger of who redeemed what, enforcing
 * `max_redemptions` (per-coupon) and an optional per-customer cap under a row lock
 * (mirrors the seat-assign lock — never over-redeem).
 *
 * `subscription_coupons` is the per-subscription BINDING: a snapshot of the discount plus a
 * remaining-periods counter the renewal invoicer decrements. Snapshotting decouples an
 * in-flight discount from a later edit/delete of the coupon, so a subscriber is never
 * silently repriced by an operator editing the coupon.
 *
 * Additive only — no existing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            // Stored upper-cased so lookup is case-insensitive on a plain unique index.
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('discount_type'); // percent | fixed_amount
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('duration')->default('once'); // once | repeating | forever
            $table->unsignedInteger('duration_in_periods')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->unsignedInteger('max_redemptions_per_customer')->nullable();
            $table->timestamp('redeem_by')->nullable();
            $table->string('applies_to')->default('all'); // all | plans
            $table->json('applies_to_plans')->nullable();  // list of plan keys when applies_to = plans
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('active');
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('organization_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index('coupon_id');
            $table->index(['coupon_id', 'organization_id']);
            $table->index('organization_id');
            $table->index('subscription_id');
        });

        Schema::create('subscription_coupons', function (Blueprint $table): void {
            $table->id();
            // One active coupon binding per subscription (a re-redeem replaces it).
            $table->unsignedBigInteger('subscription_id')->unique();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            // Snapshot of the discount — decoupled from a later edit/delete of the coupon.
            $table->string('code');
            $table->string('discount_type');
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('duration');
            // Invoices still owed the discount; null = infinite (forever). Decremented per
            // issued period invoice.
            $table->unsignedInteger('remaining_periods')->nullable();
            $table->timestamps();

            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_coupons');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
