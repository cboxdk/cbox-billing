<?php

declare(strict_types=1);

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account's chosen billing currency (ISO 4217). Nullable until the account picks
 * one at signup; once its FIRST invoice finalizes, the engine's one-way
 * {@see BillingCurrencyLock} pins the currency for good.
 * This column records the *choice* (so quotes and the first invoice know which
 * per-currency price to use); the lock table is the authority thereafter. An account
 * with neither a choice nor a lock falls back to the app's default currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('billing_currency', 3)->nullable()->after('billing_email');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('billing_currency');
        });
    }
};
