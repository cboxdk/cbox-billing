<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive lookup indexes for three read paths flagged as unindexed scans:
 *
 *  - `plan_features.feature_id` — the unique key is `(plan_id, feature_id)`, whose leading
 *    column is `plan_id`, so a feature-keyed lookup ("which plans grant this feature?") could
 *    not use it. A standalone `feature_id` index makes that lookup a seek.
 *  - `fx_rates (base, quote, source, as_of_date)` — the console rates view groups by
 *    `(base, quote, source)` taking the latest `as_of_date`; the existing
 *    `(base, quote, as_of_date)` index omits `source`, so the grouped "latest per source"
 *    read scanned. This covering order lets it resolve the MAX per group from the index.
 *  - `operator_audit_events.occurred_at` — the operator audit search filters and orders by
 *    `occurred_at`, which had no index (only `actor_sub`/`action`/etc. were indexed).
 *
 * Purely additive — no column or data change. Safe to run online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_features', function (Blueprint $table): void {
            $table->index('feature_id');
        });

        Schema::table('fx_rates', function (Blueprint $table): void {
            $table->index(['base', 'quote', 'source', 'as_of_date'], 'fx_rates_pair_source_date_index');
        });

        Schema::table('operator_audit_events', function (Blueprint $table): void {
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_features', function (Blueprint $table): void {
            $table->dropIndex(['feature_id']);
        });

        Schema::table('fx_rates', function (Blueprint $table): void {
            $table->dropIndex('fx_rates_pair_source_date_index');
        });

        Schema::table('operator_audit_events', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at']);
        });
    }
};
