<?php

declare(strict_types=1);

use Cbox\Billing\Catalog\Enums\PricingModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiered pricing on a plan's per-currency price (engine v0.8 catalog depth). A plan price
 * gains a `pricing_model` — how it turns a quantity into an amount ({@see PricingModel}):
 * `flat` (the existing recurring amount, unchanged for every current row), `per_unit`,
 * or one of the tiered models (`graduated` / `volume` / `package` / `stairstep`) whose
 * brackets live in `plan_price_tiers`. `package_size` is the block size for the `package`
 * model. `price_minor` remains the base/list recurring amount the MRR read model sums; the
 * tiers describe how the charge scales with quantity (seats / units).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table): void {
            $table->string('pricing_model')->default('flat')->after('price_minor');
            $table->unsignedInteger('package_size')->nullable()->after('pricing_model');
        });

        Schema::create('plan_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_price_id')->constrained('plan_prices')->cascadeOnDelete();
            // Inclusive upper bound of the tier IN UNITS, or null for the final unbounded (∞) tier.
            $table->unsignedBigInteger('up_to')->nullable();
            // Per-unit price within the tier (graduated / volume); integer minor units.
            $table->unsignedBigInteger('unit_minor')->default(0);
            // Optional flat amount: a per-tier fee (graduated/volume), the block price
            // (package), or the whole-bracket price (stairstep).
            $table->unsignedBigInteger('flat_minor')->nullable();
            // Deterministic ordering: tiers ascend by bound.
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['plan_price_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_price_tiers');

        Schema::table('plan_prices', function (Blueprint $table): void {
            $table->dropColumn(['pricing_model', 'package_size']);
        });
    }
};
