<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-currency recurring price of a plan. A plan can be priced in several ISO
 * currencies (DKK, EUR, USD, …); each row carries one currency's amount in integer
 * minor units (never a float). The account's billing currency selects which row's
 * amount applies — quotes, invoices and proration all run in that one currency. A
 * currency the plan is not priced in has no row and cannot be transacted in
 * (deny-by-default: `Plan::priceFor()` refuses rather than inventing a rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('price_minor');
            $table->timestamps();

            $table->unique(['plan_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
