<?php

declare(strict_types=1);

use App\Billing\Hosted\CheckoutActivation;
use App\Billing\Hosted\CheckoutPaymentFlow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hosted checkout session can carry a promo `coupon_code`: the code the up-front charge is
 * discounted by ({@see CheckoutPaymentFlow}) and redeemed + bound to the
 * new subscription when the settled webhook activates it
 * ({@see CheckoutActivation}). Additive, nullable — a checkout without a
 * promo code is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->string('coupon_code')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->dropColumn('coupon_code');
        });
    }
};
