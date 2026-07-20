<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money integrity for hosted checkout (re-review remediation): record the tax-aware GROSS a
 * checkout session's PaymentIntent was created for, so the later settled webhook can verify the
 * settled amount + currency match what the customer was actually asked to pay BEFORE it subscribes
 * the org. Without this, activation trusted the settlement reference alone — a settlement carrying
 * the wrong amount/currency would still have activated the subscription.
 *
 * Additive and backfill-safe: the columns are nullable and only stamped when an intent is created.
 * A legacy session with no stamped expectation (null) still activates (backward-compatible); every
 * new checkout carries its expected charge and is verified against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_amount_minor')->nullable()->after('payment_reference');
            $table->string('expected_currency', 3)->nullable()->after('expected_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('billing_sessions', function (Blueprint $table): void {
            $table->dropColumn(['expected_amount_minor', 'expected_currency']);
        });
    }
};
