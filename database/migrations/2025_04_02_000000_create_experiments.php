<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B pricing experiments — run controlled pricing experiments on the public storefront and
 * measure conversion by variant. Four additive tables, none of which the metering or billing
 * paths read; they sit alongside the pricing-table storefront (#57) and project onto it:
 *
 *  1. `experiments` — the experiment definition: the `key`, the `pricing_table_id` it runs on
 *     (its `/pricing/{key}` is intercepted while running), the lifecycle `status`, the
 *     `primary_metric` it optimises for (checkout-started / -completed), and the
 *     `promoted_variant_id` a concluded experiment can point the page at (the winner).
 *  2. `experiment_variants` — the arms: a label, whether it is the required control, a
 *     non-negative integer traffic `weight`, and the `served_pricing_table_id` the variant
 *     shows (null = the experiment's base table, the natural control default).
 *  3. `experiment_impressions` — one row per (variant, visitor), UNIQUE so an impression is
 *     counted once per anonymous visitor.
 *  4. `experiment_conversions` — one row per (variant, visitor, kind), UNIQUE so a conversion
 *     (checkout started, then completed on settlement) is idempotent under webhook re-delivery.
 *
 * The `visitor_id` throughout is an opaque, random anonymous cookie id — privacy-preserving,
 * never a customer identifier, no PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiments', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 160);
            $table->text('hypothesis')->nullable();
            $table->string('status', 20)->default('draft');
            // The event the experiment optimises for; also the conversion kind the results count.
            $table->string('primary_metric', 40)->default('checkout_completed');
            // The public pricing table the experiment runs on — its /pricing/{key} is intercepted
            // while the experiment is running. Cascades so removing a table cleans its experiments.
            $table->foreignId('pricing_table_id')->constrained('pricing_tables')->cascadeOnDelete();
            // The winning variant a concluded experiment promoted; the base page then serves its
            // table permanently. FK added after experiment_variants exists (below).
            $table->unsignedBigInteger('promoted_variant_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamps();

            $table->index(['pricing_table_id', 'status']);
        });

        Schema::create('experiment_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->string('label', 80);
            // Exactly one control is required per experiment (the baseline lift is measured against).
            $table->boolean('is_control')->default(false);
            // Relative traffic weight (not a percentage): a visitor is bucketed in proportion to
            // the weights. Non-negative; the weights across an experiment must sum to > 0.
            $table->unsignedInteger('weight')->default(1);
            $table->integer('sort_order')->default(0);
            // The pricing table this variant serves; null = serve the experiment's base table.
            $table->foreignId('served_pricing_table_id')->nullable()->constrained('pricing_tables')->nullOnDelete();
            $table->timestamps();
        });

        // The winner FK, now that experiment_variants exists. nullOnDelete so deleting the
        // promoted variant simply un-promotes rather than orphaning.
        Schema::table('experiments', function (Blueprint $table): void {
            $table->foreign('promoted_variant_id')->references('id')->on('experiment_variants')->nullOnDelete();
        });

        Schema::create('experiment_impressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->foreignId('experiment_variant_id')->constrained('experiment_variants')->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->timestamp('first_seen_at');
            $table->timestamps();

            // An impression is counted once per anonymous visitor per variant.
            $table->unique(['experiment_variant_id', 'visitor_id']);
            $table->index('experiment_id');
        });

        Schema::create('experiment_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->foreignId('experiment_variant_id')->constrained('experiment_variants')->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('kind', 40);
            // The hosted checkout session that carried the attribution (joins a settlement to its
            // earlier start). UUID string, nullable so a direct start with no session still records.
            $table->uuid('billing_session_id')->nullable();
            $table->timestamp('converted_at');
            $table->timestamps();

            // A conversion is idempotent per anonymous visitor per variant per kind.
            $table->unique(['experiment_variant_id', 'visitor_id', 'kind']);
            $table->index('experiment_id');
            $table->index('billing_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_conversions');
        Schema::dropIfExists('experiment_impressions');

        Schema::table('experiments', function (Blueprint $table): void {
            $table->dropForeign(['promoted_variant_id']);
        });

        Schema::dropIfExists('experiment_variants');
        Schema::dropIfExists('experiments');
    }
};
