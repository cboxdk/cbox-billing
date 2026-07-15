<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price point of a product. The recurring price is carried in integer minor units
 * plus an ISO currency (never a float), billed every `interval`. Its credit grants
 * and metered entitlements live in the child tables `plan_credit_grants` and
 * `plan_entitlements`.
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
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
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
