<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales a selling entity made into a US state through OTHER channels — marketplaces
 * (Amazon/eBay), a separate storefront, another billing system — that this platform
 * never invoiced but that still count toward the state's economic-nexus threshold.
 * Operator-declared per state and calendar year (a figure a firm reconciles from each
 * channel's own reports), so the nexus measure reflects TOTAL activity, not just ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_external_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('environment')->index();
            $table->string('seller_entity_id');
            $table->string('subdivision');            // ISO 3166-2, e.g. "US-CA"
            $table->unsignedSmallInteger('period_year'); // calendar year the figures cover
            $table->unsignedBigInteger('sales_dollars')->default(0); // whole USD
            $table->unsignedInteger('transactions')->default(0);
            $table->string('source')->nullable();     // channel label, e.g. "Amazon Marketplace"
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('seller_entity_id')->references('id')->on('seller_entities')->cascadeOnDelete();
            $table->index(['seller_entity_id', 'subdivision', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_external_sales');
    }
};
