<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\PlanFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The embeddable pricing-table / storefront dimension (#57) — an operator-authored, public,
 * branded pricing surface a marketing site drops in, projected from the SAME catalog the
 * engine prices and provisions from (products / plans / per-currency prices / features).
 * Three additive tables, none of which the metering or billing paths read:
 *
 *  1. `pricing_tables` — the table definition. `key` is a public, unguessable-free slug that
 *     addresses the no-auth `/pricing/{key}` page (an inactive/unknown key 404s). It carries
 *     which currencies it may present, whether to offer the monthly/yearly interval toggle,
 *     the CTA label + deep-link target template (the checkout hand-off contract), the selling
 *     entity whose branding the page wraps around, and `active`.
 *  2. `pricing_table_plans` — the ordered plan columns. Each column names the (monthly) plan
 *     to show, its display order, whether it is the featured/highlighted column (+ an optional
 *     badge/highlight label), and — for the interval toggle — an optional `annual_plan_id`, the
 *     yearly-priced sibling plan the toggle swaps to.
 *  3. `pricing_table_features` — the ordered feature rows of the comparison matrix: which
 *     catalog {@see Feature}s to compare across the columns (✓/✗/value read from
 *     each column plan's {@see PlanFeature} grant).
 *
 * A plan/feature referenced here is only ever archived in the catalog, never hard-deleted, so
 * a column or a matrix row never orphans; a cascade still cleans the pivots if a row is truly
 * removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_tables', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 160);
            // The selling entity whose branding (logo, accent, legal name) the page wraps
            // around. Null falls back to the default seller / app-level branding defaults.
            $table->string('seller_entity_id')->nullable();
            // The ISO currencies this table may present (a JSON list). Null/empty = the union
            // of currencies its plans are actually priced in (deny-by-default, no fabrication).
            $table->json('currencies')->nullable();
            // The currency selected on first render; must be one the table presents.
            $table->string('default_currency', 3)->nullable();
            // Whether to offer the monthly/yearly toggle (only shown when a column actually
            // carries an annual sibling plan).
            $table->boolean('interval_toggle')->default(true);
            // The per-plan call-to-action: the button label and the deep-link target the CTA
            // points at, with `{plan}` / `{currency}` / `{interval}` / `{price}` placeholders
            // substituted (the checkout hand-off contract). Null falls back to the configured
            // storefront checkout URL, then the app root, always carrying the params as a query.
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url_template', 2048)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('pricing_table_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_table_id')->constrained('pricing_tables')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            // The yearly-priced sibling plan the interval toggle swaps this column to, if any.
            $table->foreignId('annual_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            // The featured/highlighted column — visually lifted, at most a marketing emphasis.
            $table->boolean('featured')->default(false);
            // Optional column badge ("Most popular") and a one-line highlight/tagline.
            $table->string('badge', 40)->nullable();
            $table->string('highlight', 120)->nullable();
            $table->timestamps();

            // A plan appears at most once as a column of a given table.
            $table->unique(['pricing_table_id', 'plan_id']);
        });

        Schema::create('pricing_table_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_table_id')->constrained('pricing_tables')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // A feature is compared at most once per table.
            $table->unique(['pricing_table_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_table_features');
        Schema::dropIfExists('pricing_table_plans');
        Schema::dropIfExists('pricing_tables');
    }
};
