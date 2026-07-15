<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price point of a product, billed every `interval`. A plan can be priced in several
 * ISO currencies: the recurring amounts live one-per-currency in the child table
 * `plan_prices` (integer minor units, never a float), so the account's currency selects
 * which amount applies. Its credit grants and metered entitlements live in the child
 * tables `plan_credit_grants` and `plan_entitlements`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('interval')->default('month');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
